<?PHP
/* One watcher cycle (command line only; run every few seconds by scripts/watch):
 * compares the current LLDP neighbours with the stored state, records changes in
 * the history, and sends Unraid notifications when enabled.
 *   php poll.php          one poll
 *   php poll.php --flush  write pending history to /boot (used when the service stops)
 *   php poll.php --reset  --flush, then forget the RAM state (next start re-reads /boot)
 * Environment (tests only): ULLDPD_GRACE = seconds before a vanished neighbour counts as removed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once '/usr/local/emhttp/plugins/ulldpd/include/ulldpd_config.php';

$mode = $argv[1] ?? '';
if (!in_array($mode, ['', '--flush', '--reset'], true)) exit(2);
$grace = ULLDPD_GRACE;
$g = getenv('ULLDPD_GRACE');
if (is_string($g) && ctype_digit($g) && (int)$g >= 1 && (int)$g <= 3600) $grace = (int)$g;

$lock = ulldpd_lock();
if (!$lock) exit(1);
$now = time();
list($state, $fresh) = ulldpd_state_load();
if ($fresh) $state['meta']['seeded'] = false;

if ($mode !== '') {
    if ($state['meta']['dirty'] && ulldpd_state_save_boot($state)) $state['meta']['dirty'] = false;
    if ($mode === '--reset') @unlink(ULLDPD_STATE_FILE); else ulldpd_state_save_ram($state);
    exit(0);
}

if (!ulldpd_pid()) exit(0);                       /* lldpd not running: nothing to compare */
$rows = ulldpd_neighbors_result();
if ($rows === null) exit(0);                      /* lldpcli failed: keep the state */

$cfg = ulldpd_load_cfg();
/* after a restart, wait at least one full neighbour TTL (tx interval x hold) before counting absences */
$hold = max(ULLDPD_STARTUP, min(7200, (int)$cfg['TX_INTERVAL'] * (int)$cfg['TX_HOLD']));
$events = ulldpd_poll_step($state, ulldpd_current_map($rows), $now, $grace, $hold);

if ($cfg['NOTIFY'] === 'enable') {
    $send = function ($level, $subject, $desc, $uniq = '') { return ulldpd_notify_send($level, $subject, $desc, $uniq); };
    foreach ($events as $i => $ev) {
        $key = $ev['if'] . '|' . $ev['kind'];
        if (!ulldpd_rl_allow($state, $now, $key)) continue;
        list($subject, $desc) = ulldpd_event_text($ev);
        if ($send($cfg['NOTIFY_LEVEL'], $subject, $desc, $now . '-' . $i)) ulldpd_rl_commit($state, $now, $key);
        else ulldpd_rl_failed($state);
    }
    ulldpd_rl_summary($state, $now, $cfg['NOTIFY_LEVEL'], $send);
}

/* flash: only when something changed, and not more often than once a minute */
if ($state['meta']['dirty'] && $now - $state['meta']['boot_written'] >= ULLDPD_BOOT_INTERVAL) {
    if (ulldpd_state_save_boot($state)) { $state['meta']['dirty'] = false; $state['meta']['boot_written'] = $now; }
}
ulldpd_state_save_ram($state);
exit(0);
