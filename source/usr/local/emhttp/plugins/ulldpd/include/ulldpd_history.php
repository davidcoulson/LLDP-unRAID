<?PHP
/* Neighbour history and change notifications.
 *
 * State (last known neighbour per interface, "previous" neighbour after a change,
 * the last events, plus watcher bookkeeping) lives in RAM (ULLDPD_STATE_FILE).
 * The persistent part is copied to /boot/config/plugins/ulldpd/history.json only
 * when something changed (at most once a minute) and when the watcher stops.
 * Everything read back from those files is validated and size-limited, and
 * everything from lldpcli is remote-controlled text: it is stored as plain
 * bounded strings and escaped on output / sanitised for notifications.
 */
const ULLDPD_HIST_EVENTS   = 20;     /* events kept */
const ULLDPD_HIST_MAXFILE  = 65536;  /* bytes; larger files are ignored / never written */
const ULLDPD_GRACE         = 60;     /* seconds a neighbour must be gone before it counts as removed */
const ULLDPD_RL_WINDOW     = 600;    /* notification rate limit: max ULLDPD_RL_MAX per window ... */
const ULLDPD_RL_MAX        = 5;
const ULLDPD_RL_SPACING    = 120;    /* ... and at most one per interface+kind in this many seconds */
const ULLDPD_STARTUP       = 90;     /* seconds after the watcher start during which an empty history is learned silently */
const ULLDPD_MAX_NEIGHBORS = 64;     /* tracked neighbours in total */
const ULLDPD_BOOT_INTERVAL = 60;     /* minimum seconds between two flash writes */

/* bounded plain string: valid UTF-8, no control characters */
function ulldpd_hist_str($v, $max = 64) {
    if (!is_scalar($v)) return '';
    $s = mb_scrub((string)$v, 'UTF-8');
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s);
    return trim(mb_substr((string)$s, 0, $max, 'UTF-8'));
}

function ulldpd_hist_int($v) {
    return (is_int($v) || (is_string($v) && ctype_digit($v))) && (int)$v >= 0 && (int)$v < 4000000000 ? (int)$v : 0;
}

function ulldpd_hist_entry($e) {
    return [
        'if'        => is_string($e['if'] ?? null) && ulldpd_ifname_valid($e['if']) ? $e['if'] : '',
        'name'      => ulldpd_hist_str($e['name'] ?? ''),
        'chassis'   => ulldpd_hist_str($e['chassis'] ?? ''),
        'port'      => ulldpd_hist_str($e['port'] ?? ''),
        'portdescr' => ulldpd_hist_str($e['portdescr'] ?? ''),
        'first'     => ulldpd_hist_int($e['first'] ?? 0),
        'last'      => ulldpd_hist_int($e['last'] ?? 0),
        'until'     => ulldpd_hist_int($e['until'] ?? 0),
    ];
}

/* Stable identity of a tracked neighbour: interface + chassis ID + port ID. */
function ulldpd_nkey(array $e) {
    return $e['if'] . '|' . substr(sha1($e['chassis'] . "\0" . $e['port']), 0, 12);
}

function ulldpd_nkey_valid($k) {
    return is_string($k) && strlen($k) <= 100 && preg_match('/\A[^|]{1,80}\|[0-9a-f]{12}\z/', $k);
}

function ulldpd_hist_empty() {
    return ['v' => 1, 'neighbors' => [], 'previous' => [], 'events' => [],
            'meta' => ['seeded' => false, 'start' => 0, 'learn' => false, 'dirty' => false, 'boot_written' => 0, 'hold_until' => 0, 'absent' => [],
                       'rl' => ['win' => 0, 'sent' => 0, 'supp' => 0, 'pend' => 0, 'last' => []]]];
}

/* Validate decoded JSON into a clean state (anything unexpected is dropped). */
function ulldpd_hist_clean($raw) {
    $s = ulldpd_hist_empty();
    if (!is_array($raw)) return $s;
    if (isset($raw['previous']) && is_array($raw['previous'])) {
        foreach (array_slice($raw['previous'], 0, ULLDPD_MAX_PORTS, true) as $if => $e) {
            if (is_string($if) && ulldpd_ifname_valid($if) && is_array($e)) { $e['if'] = $if; $s['previous'][$if] = ulldpd_hist_entry($e); }
        }
    }
    if (isset($raw['neighbors']) && is_array($raw['neighbors'])) {
        foreach (array_slice($raw['neighbors'], 0, ULLDPD_MAX_NEIGHBORS, true) as $k => $e) {
            if (!is_array($e)) continue;
            /* older files were keyed by the plain interface name */
            if (is_string($k) && ulldpd_ifname_valid($k)) $e['if'] = $k;
            $en = ulldpd_hist_entry($e);
            if ($en['if'] === '') continue;
            $s['neighbors'][ulldpd_nkey($en)] = $en;
        }
    }
    if (isset($raw['events']) && is_array($raw['events'])) {
        foreach (array_slice(array_values($raw['events']), -ULLDPD_HIST_EVENTS) as $e) {
            if (!is_array($e) || !isset($e['if']) || !is_string($e['if']) || !ulldpd_ifname_valid($e['if'])) continue;
            if (!isset($e['kind']) || !in_array($e['kind'], ['added', 'removed', 'changed'], true)) continue;
            $s['events'][] = [
                't' => ulldpd_hist_int($e['t'] ?? 0), 'if' => $e['if'], 'kind' => $e['kind'],
                'name' => ulldpd_hist_str($e['name'] ?? ''), 'port' => ulldpd_hist_str($e['port'] ?? ''),
                'old_name' => ulldpd_hist_str($e['old_name'] ?? ''), 'old_port' => ulldpd_hist_str($e['old_port'] ?? ''),
            ];
        }
    }
    if (isset($raw['meta']) && is_array($raw['meta'])) {
        $m = $raw['meta'];
        $s['meta']['seeded'] = !empty($m['seeded']);
        $s['meta']['dirty'] = !empty($m['dirty']);
        $s['meta']['start'] = ulldpd_hist_int($m['start'] ?? 0);
        $s['meta']['hold_until'] = ulldpd_hist_int($m['hold_until'] ?? 0);
        $s['meta']['learn'] = !empty($m['learn']);
        $s['meta']['boot_written'] = ulldpd_hist_int($m['boot_written'] ?? 0);
        if (isset($m['absent']) && is_array($m['absent'])) {
            foreach (array_slice($m['absent'], 0, ULLDPD_MAX_NEIGHBORS, true) as $k => $t) if (ulldpd_nkey_valid($k)) $s['meta']['absent'][$k] = ulldpd_hist_int($t);
        }
        if (isset($m['rl']) && is_array($m['rl'])) {
            $rl = $m['rl'];
            $s['meta']['rl']['win'] = ulldpd_hist_int($rl['win'] ?? 0);
            $s['meta']['rl']['sent'] = min(1000, ulldpd_hist_int($rl['sent'] ?? 0));
            $s['meta']['rl']['supp'] = min(100000, ulldpd_hist_int($rl['supp'] ?? 0));
            $s['meta']['rl']['pend'] = min(100000, ulldpd_hist_int($rl['pend'] ?? 0));
            if (isset($rl['last']) && is_array($rl['last'])) {
                foreach (array_slice($rl['last'], 0, 200, true) as $k => $t) if (is_string($k) && strlen($k) <= 40) $s['meta']['rl']['last'][$k] = ulldpd_hist_int($t);
            }
        }
    }
    return $s;
}

function ulldpd_json_file($file) {
    $sz = @filesize($file);
    if ($sz === false || $sz > ULLDPD_HIST_MAXFILE) return null;
    $txt = @file_get_contents($file);
    if (!is_string($txt)) return null;
    $j = json_decode($txt, true, 8, JSON_INVALID_UTF8_SUBSTITUTE);
    return is_array($j) ? $j : null;
}

/* Current state: the RAM copy if present, else the persisted history. [state, fresh] */
function ulldpd_state_load() {
    $j = ulldpd_json_file(ULLDPD_STATE_FILE);
    if ($j !== null) return [ulldpd_hist_clean($j), false];
    $j = ulldpd_json_file(ULLDPD_HISTORY_FILE);
    $s = ulldpd_hist_clean($j);
    $s['meta']['seeded'] = false;
    return [$s, true];
}

/* Atomic write (unique temp file in the same directory + rename). */
function ulldpd_write_json_atomic($file, $data, $mode = 0644) {
    $txt = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($txt) || strlen($txt) > ULLDPD_HIST_MAXFILE) return false;
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    $tmp = @tempnam($dir, '.ulldpd.');
    if ($tmp === false) return false;
    if (@file_put_contents($tmp, $txt) !== strlen($txt) || !@chmod($tmp, $mode) || !@rename($tmp, $file)) { @unlink($tmp); return false; }
    return true;
}

function ulldpd_state_save_ram(array $s) {
    return ulldpd_write_json_atomic(ULLDPD_STATE_FILE, $s, 0600);
}

/* persistent part only */
function ulldpd_state_save_boot(array $s) {
    return ulldpd_write_json_atomic(ULLDPD_HISTORY_FILE, ['v' => 1, 'neighbors' => $s['neighbors'], 'previous' => $s['previous'], 'events' => $s['events']], 0644);
}

/* Lock shared by the watcher and the Clear endpoint. Returns a handle or false. */
function ulldpd_lock() {
    $fh = @fopen('/run/ulldpd-state.lock', 'c');
    if (!$fh) return false;
    if (!flock($fh, LOCK_EX)) { fclose($fh); return false; }
    return $fh;
}

/* Every neighbour as a history entry: identity key => entry (identity = interface + chassis ID + port ID) */
function ulldpd_current_map(array $rows) {
    $cur = [];
    foreach ($rows as $r) {
        if (!ulldpd_ifname_valid($r['iface'])) continue;
        $e = ulldpd_hist_entry(['if' => $r['iface'], 'name' => $r['name'], 'chassis' => $r['chassis'], 'port' => $r['port'], 'portdescr' => $r['portdescr']]);
        $k = ulldpd_nkey($e);
        if (isset($cur[$k])) continue;
        $cur[$k] = $e;
        if (count($cur) >= ULLDPD_MAX_NEIGHBORS) break;
    }
    return $cur;
}

function ulldpd_event_add(array &$s, $now, $if, $kind, array $new, array $old) {
    $ev = ['t' => $now, 'if' => $if, 'kind' => $kind, 'name' => $new['name'] ?? '', 'port' => $new['port'] ?? '',
           'old_name' => $old['name'] ?? '', 'old_port' => $old['port'] ?? ''];
    $s['events'][] = $ev;
    $s['events'] = array_slice($s['events'], -ULLDPD_HIST_EVENTS);
    $s['meta']['dirty'] = true;
    return $ev;
}

/* Compare the current neighbours (identity key => entry) with the state and update it.
 * $hold: seconds after a restart (baseline loaded from /boot) during which missing neighbours are not yet
 * counted as absent, so slowly advertising neighbours are not reported removed and added. */
function ulldpd_poll_step(array &$s, array $cur, $now, $grace = ULLDPD_GRACE, $hold = ULLDPD_STARTUP) {
    $events = [];
    $baseline = empty($s['meta']['seeded']);
    if ($baseline) {
        $s['meta']['start'] = $now;
        $s['meta']['learn'] = !$s['neighbors'] && !$s['events'] && !$s['previous'];
        $s['meta']['hold_until'] = $s['neighbors'] ? $now + $hold : 0;
        $s['meta']['absent'] = [];
    }
    /* first ever run / after Clear: learn the neighbours that show up while the service is still starting */
    $silent = !empty($s['meta']['learn']) && !$s['events'] && !$s['previous'] && $now - $s['meta']['start'] < ULLDPD_STARTUP;
    $holding = $now < ($s['meta']['hold_until'] ?? 0);

    $new = []; $missing = [];
    foreach ($cur as $k => $e) {
        unset($s['meta']['absent'][$k]);
        $old = $s['neighbors'][$k] ?? null;
        if ($old === null) { $new[$e['if']][$k] = $e; continue; }
        if ($old['name'] !== $e['name']) {
            $prev = $old; $prev['until'] = $now;
            $s['previous'][$e['if']] = $prev;
            $e['first'] = $old['first'] ?: $now;
            $e['last'] = $now;
            $s['neighbors'][$k] = $e;
            $events[] = ulldpd_event_add($s, $now, $e['if'], 'changed', $e, $old);
        } else {
            $s['neighbors'][$k]['last'] = $now;
            $s['neighbors'][$k]['portdescr'] = $e['portdescr'];
        }
    }
    foreach ($s['neighbors'] as $k => $old) if (!isset($cur[$k])) $missing[$old['if']][] = $k;

    foreach ($new as $if => $list) {
        /* exactly one neighbour replaced by exactly one other on the same interface: a change */
        if (!$holding && count($list) === 1 && isset($missing[$if]) && count($missing[$if]) === 1) {
            $k = key($list); $e = $list[$k]; $ok = $missing[$if][0]; $old = $s['neighbors'][$ok];
            $prev = $old; $prev['until'] = $now;
            $s['previous'][$if] = $prev;
            unset($s['neighbors'][$ok], $s['meta']['absent'][$ok], $missing[$if]);
            $e['first'] = $e['last'] = $now;
            $s['neighbors'][$k] = $e;
            $events[] = ulldpd_event_add($s, $now, $if, 'changed', $e, $old);
            continue;
        }
        foreach ($list as $k => $e) {
            $e['first'] = $e['last'] = $now;
            $s['neighbors'][$k] = $e;
            $s['meta']['dirty'] = true;
            if (!$silent) $events[] = ulldpd_event_add($s, $now, $if, 'added', $e, []);
        }
    }
    if (!$holding) {
        foreach ($missing as $if => $keys) foreach ($keys as $k) {
            if (!isset($s['neighbors'][$k])) continue;
            $old = $s['neighbors'][$k];
            if (!isset($s['meta']['absent'][$k])) $s['meta']['absent'][$k] = $now;
            if ($now - $s['meta']['absent'][$k] >= $grace) {
                $prev = $old; $prev['until'] = $now;
                $s['previous'][$if] = $prev;
                unset($s['neighbors'][$k], $s['meta']['absent'][$k]);
                $events[] = ulldpd_event_add($s, $now, $if, 'removed', [], $old);
            }
        }
    }
    foreach (array_keys($s['meta']['absent']) as $k) if (!isset($s['neighbors'][$k])) unset($s['meta']['absent'][$k]);
    $s['meta']['seeded'] = true;
    return $events;
}

/* Text for notifications: plain ASCII subset only, length-limited. */
function ulldpd_notify_text($v, $max = 48) {
    $s = mb_scrub((string)$v, 'UTF-8');
    $s = preg_replace('/[^\x20-\x7E]/u', '?', $s);
    $s = preg_replace('/[^A-Za-z0-9 ._,:\/@+=#-]/', '?', (string)$s);
    $s = trim(ltrim((string)$s, '-'));
    return strlen($s) > $max ? substr($s, 0, $max - 1) . '~' : $s;
}

/* [subject, description] of the notification for an event */
function ulldpd_event_text(array $ev) {
    $if = ulldpd_notify_text($ev['if'], 16);
    $nb = function ($name, $port) {
        $n = ulldpd_notify_text($name) ?: 'unknown';
        return $n . ($port !== '' ? ' port ' . ulldpd_notify_text($port) : '');
    };
    if ($ev['kind'] === 'added')   return ["LLDP neighbor added on $if", $nb($ev['name'], $ev['port'])];
    if ($ev['kind'] === 'removed') return ["LLDP neighbor removed from $if", $nb($ev['old_name'], $ev['old_port']) . ' is gone'];
    return ["LLDP neighbor changed on $if", 'was ' . $nb($ev['old_name'], $ev['old_port']) . ', now ' . $nb($ev['name'], $ev['port'])];
}

/* Rate limiter, step 1: may one more notification for $key be sent now? (counts a suppression if not) */
function ulldpd_rl_allow(array &$s, $now, $key) {
    $rl =& $s['meta']['rl'];
    if ($now - $rl['win'] >= ULLDPD_RL_WINDOW || $rl['win'] > $now) {
        /* new window: what was suppressed in the old one is reported by ulldpd_rl_summary() */
        $rl['pend'] = min(100000, $rl['pend'] + $rl['supp']);
        $rl['supp'] = 0; $rl['win'] = $now; $rl['sent'] = 0;
    }
    $key = substr($key, 0, 40);
    if ($rl['sent'] >= ULLDPD_RL_MAX || (isset($rl['last'][$key]) && $now - $rl['last'][$key] < ULLDPD_RL_SPACING)) { $rl['supp']++; return false; }
    return true;
}

/* step 2: call only after the notification was really delivered */
function ulldpd_rl_commit(array &$s, $now, $key) {
    $rl =& $s['meta']['rl'];
    $rl['sent']++;
    $rl['last'][substr($key, 0, 40)] = $now;
    foreach ($rl['last'] as $k => $t) if ($now - $t > 2 * ULLDPD_RL_WINDOW) unset($rl['last'][$k]);
}

/* A delivery failed: it counts as suppressed so that it shows up in the summary. */
function ulldpd_rl_failed(array &$s) {
    $s['meta']['rl']['supp']++;
}

/* Send an Unraid notification (argv, no shell). Unraid names the archive file after event + current second and
 * skips it if it exists, so every call gets a distinct event name ($uniq). */
function ulldpd_notify_send($level, $subject, $desc, $uniq = '') {
    if (!is_executable(ULLDPD_NOTIFY)) return false;
    if (!in_array($level, ['normal', 'warning'], true)) $level = 'normal';
    $event = 'LLDP' . ($uniq !== '' ? ' ' . preg_replace('/[^A-Za-z0-9._ -]/', '', $uniq) : '');
    list($rc) = ulldpd_run([ULLDPD_NOTIFY, '-e', $event, '-s', $subject, '-d', $desc, '-i', $level, '-l', '/Settings/ULLDPDsettings', 'add'], 15);
    return $rc === 0;
}

/* Summary of suppressed notifications: those of an earlier window, or of the current one once it has ended. */
function ulldpd_rl_summary(array &$s, $now, $level, $send) {
    $rl =& $s['meta']['rl'];
    $n = $rl['pend'] + ($now - $rl['win'] >= ULLDPD_RL_WINDOW ? $rl['supp'] : 0);
    if ($n <= 0) return;
    if ($now - $rl['win'] >= ULLDPD_RL_WINDOW) { $rl['supp'] = 0; $rl['win'] = $now; $rl['sent'] = 0; }
    $rl['pend'] = 0;
    if ($send($level, 'LLDP neighbor events suppressed', "$n further neighbor events were not notified (rate limit); see the LLDP settings page", 'summary')) $rl['sent']++;
    else $rl['pend'] = $n;
}

/* ---- HTML ---- */

function ulldpd_time_h($t) {
    return $t > 0 ? ulldpd_h(date('Y-m-d H:i:s', $t)) : '';
}

/* "previously on X" note for an interface whose neighbour changed (HTML, escaped); '' if none */
function ulldpd_previous_html(array $state, array $row) {
    $p = $state['previous'][$row['iface']] ?? null;
    if (!$p) return '';
    foreach ($state['neighbors'] as $cur) {
        if ($cur['if'] === $row['iface'] && $cur['chassis'] === $p['chassis'] && $cur['port'] === $p['port'] && $cur['name'] === $p['name']) return '';
    }
    $what = ($p['name'] !== '' ? $p['name'] : $p['chassis']) . ($p['port'] !== '' ? ' / ' . $p['port'] : '');
    return '<br><small class="orange-text" title="Neighbor history">previously on ' . ulldpd_h($row['iface']) . ': ' . ulldpd_h($what)
        . ($p['until'] ? ' (until ' . ulldpd_time_h($p['until']) . ')' : '') . '</small>';
}

function ulldpd_history_html(array $state) {
    $ev = array_reverse($state['events']);
    if (!$ev) return '<table class="unraid tablesorter ulldpd-table"><tbody><tr><td><em>No neighbor changes recorded.</em></td></tr></tbody></table>';
    $h = '<table class="unraid tablesorter ulldpd-table"><thead><tr><th>Time</th><th>Interface</th><th>Event</th><th>Neighbor</th><th>Previous neighbor</th></tr></thead><tbody>';
    foreach ($ev as $e) {
        $nb = $e['kind'] === 'removed' ? '' : ($e['name'] . ($e['port'] !== '' ? ' / ' . $e['port'] : ''));
        $old = $e['kind'] === 'added' ? '' : ($e['old_name'] . ($e['old_port'] !== '' ? ' / ' . $e['old_port'] : ''));
        $h .= '<tr><td>' . ulldpd_time_h($e['t']) . '</td><td>' . ulldpd_h($e['if']) . '</td><td>' . ulldpd_h($e['kind']) . '</td><td>' . ulldpd_h($nb) . '</td><td>' . ulldpd_h($old) . '</td></tr>';
    }
    return $h . '</tbody></table>';
}
