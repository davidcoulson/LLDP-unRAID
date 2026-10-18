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
        'name'      => ulldpd_hist_str($e['name'] ?? ''),
        'chassis'   => ulldpd_hist_str($e['chassis'] ?? ''),
        'port'      => ulldpd_hist_str($e['port'] ?? ''),
        'portdescr' => ulldpd_hist_str($e['portdescr'] ?? ''),
        'first'     => ulldpd_hist_int($e['first'] ?? 0),
        'last'      => ulldpd_hist_int($e['last'] ?? 0),
        'until'     => ulldpd_hist_int($e['until'] ?? 0),
    ];
}

function ulldpd_hist_empty() {
    return ['v' => 1, 'neighbors' => [], 'previous' => [], 'events' => [],
            'meta' => ['seeded' => false, 'start' => 0, 'learn' => false, 'dirty' => false, 'boot_written' => 0, 'absent' => [],
                       'rl' => ['win' => 0, 'sent' => 0, 'supp' => 0, 'last' => []]]];
}

/* Validate decoded JSON into a clean state (anything unexpected is dropped). */
function ulldpd_hist_clean($raw) {
    $s = ulldpd_hist_empty();
    if (!is_array($raw)) return $s;
    foreach (['neighbors', 'previous'] as $k) {
        if (!isset($raw[$k]) || !is_array($raw[$k])) continue;
        foreach (array_slice($raw[$k], 0, ULLDPD_MAX_PORTS, true) as $if => $e) {
            if (is_string($if) && ulldpd_ifname_valid($if) && is_array($e)) $s[$k][$if] = ulldpd_hist_entry($e);
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
        $s['meta']['learn'] = !empty($m['learn']);
        $s['meta']['boot_written'] = ulldpd_hist_int($m['boot_written'] ?? 0);
        if (isset($m['absent']) && is_array($m['absent'])) {
            foreach (array_slice($m['absent'], 0, ULLDPD_MAX_PORTS, true) as $if => $t) if (is_string($if) && ulldpd_ifname_valid($if)) $s['meta']['absent'][$if] = ulldpd_hist_int($t);
        }
        if (isset($m['rl']) && is_array($m['rl'])) {
            $rl = $m['rl'];
            $s['meta']['rl']['win'] = ulldpd_hist_int($rl['win'] ?? 0);
            $s['meta']['rl']['sent'] = min(1000, ulldpd_hist_int($rl['sent'] ?? 0));
            $s['meta']['rl']['supp'] = min(100000, ulldpd_hist_int($rl['supp'] ?? 0));
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

/* First neighbour of every interface as history entries: if => entry */
function ulldpd_current_map(array $rows) {
    $cur = [];
    foreach ($rows as $r) {
        if (!ulldpd_ifname_valid($r['iface']) || isset($cur[$r['iface']])) continue;
        $cur[$r['iface']] = ulldpd_hist_entry(['name' => $r['name'], 'chassis' => $r['chassis'], 'port' => $r['port'], 'portdescr' => $r['portdescr']]);
        if (count($cur) >= ULLDPD_MAX_PORTS) break;
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

/* Compare the current neighbours with the state and update it. Returns the new events. */
function ulldpd_poll_step(array &$s, array $cur, $now, $grace = ULLDPD_GRACE) {
    $events = [];
    $baseline = empty($s['meta']['seeded']);
    if ($baseline) {
        $s['meta']['start'] = $now;
        $s['meta']['learn'] = !$s['neighbors'] && !$s['events'] && !$s['previous'];
    }
    /* first ever run / after Clear: learn the neighbours that show up while the service is still starting */
    $silent = !empty($s['meta']['learn']) && !$s['events'] && !$s['previous'] && $now - $s['meta']['start'] < ULLDPD_STARTUP;
    foreach ($cur as $if => $e) {
        unset($s['meta']['absent'][$if]);
        $old = $s['neighbors'][$if] ?? null;
        if ($old === null) {
            $e['first'] = $e['last'] = $now;
            $s['neighbors'][$if] = $e;
            $s['meta']['dirty'] = true;
            if (!$silent) $events[] = ulldpd_event_add($s, $now, $if, 'added', $e, []);
        } elseif ($old['chassis'] !== $e['chassis'] || $old['name'] !== $e['name'] || $old['port'] !== $e['port']) {
            $prev = $old; $prev['until'] = $now;
            $s['previous'][$if] = $prev;
            $e['first'] = ($old['chassis'] === $e['chassis']) ? ($old['first'] ?: $now) : $now;
            $e['last'] = $now;
            $s['neighbors'][$if] = $e;
            $events[] = ulldpd_event_add($s, $now, $if, 'changed', $e, $old);
        } else {
            $s['neighbors'][$if]['last'] = $now;
            $s['neighbors'][$if]['portdescr'] = $e['portdescr'];
        }
    }
    foreach ($s['neighbors'] as $if => $old) {
        if (isset($cur[$if])) continue;
        if (!isset($s['meta']['absent'][$if])) $s['meta']['absent'][$if] = $now;
        if ($now - $s['meta']['absent'][$if] >= $grace) {
            $prev = $old; $prev['until'] = $now;
            $s['previous'][$if] = $prev;
            unset($s['neighbors'][$if], $s['meta']['absent'][$if]);
            $events[] = ulldpd_event_add($s, $now, $if, 'removed', [], $old);
        }
    }
    foreach (array_keys($s['meta']['absent']) as $if) if (!isset($s['neighbors'][$if])) unset($s['meta']['absent'][$if]);
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

/* Rate limiter: true if one more notification for $key may be sent now (and counts it). */
function ulldpd_rl_allow(array &$s, $now, $key) {
    $rl =& $s['meta']['rl'];
    if ($now - $rl['win'] >= ULLDPD_RL_WINDOW || $rl['win'] > $now) { $rl['win'] = $now; $rl['sent'] = 0; }
    $key = substr($key, 0, 40);
    if ($rl['sent'] >= ULLDPD_RL_MAX || (isset($rl['last'][$key]) && $now - $rl['last'][$key] < ULLDPD_RL_SPACING)) { $rl['supp']++; return false; }
    $rl['sent']++;
    $rl['last'][$key] = $now;
    foreach ($rl['last'] as $k => $t) if ($now - $t > 2 * ULLDPD_RL_WINDOW) unset($rl['last'][$k]);
    return true;
}

/* Send an Unraid notification (argv, no shell). */
function ulldpd_notify_send($level, $subject, $desc) {
    if (!is_executable(ULLDPD_NOTIFY)) return false;
    if (!in_array($level, ['normal', 'warning'], true)) $level = 'normal';
    list($rc) = ulldpd_run([ULLDPD_NOTIFY, '-e', 'LLDP', '-s', $subject, '-d', $desc, '-i', $level, '-l', '/Settings/ULLDPDsettings', 'add'], 15);
    return $rc === 0;
}

/* Summary of notifications that were suppressed in a finished window (sent once the window ended). */
function ulldpd_rl_summary(array &$s, $now, $level, $send) {
    $rl =& $s['meta']['rl'];
    if ($rl['supp'] > 0 && $now - $rl['win'] >= ULLDPD_RL_WINDOW) {
        $n = $rl['supp'];
        $rl['supp'] = 0; $rl['win'] = $now; $rl['sent'] = 1;
        $send($level, 'LLDP neighbor events suppressed', "$n further neighbor events were not notified (rate limit); see the LLDP settings page");
    }
}

/* ---- HTML ---- */

function ulldpd_time_h($t) {
    return $t > 0 ? ulldpd_h(date('Y-m-d H:i:s', $t)) : '';
}

/* "previously on X" note for an interface whose neighbour changed (HTML, escaped); '' if none */
function ulldpd_previous_html(array $state, array $row) {
    $p = $state['previous'][$row['iface']] ?? null;
    if (!$p) return '';
    $cur = $state['neighbors'][$row['iface']] ?? null;
    if ($cur && $cur['chassis'] === $p['chassis'] && $cur['port'] === $p['port'] && $cur['name'] === $p['name']) return '';
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
