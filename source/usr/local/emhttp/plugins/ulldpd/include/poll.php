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
$events = ulldpd_poll_step($state, ulldpd_current_map($rows), $now, $grace);

if ($cfg['NOTIFY'] === 'enable') {
    $send = function ($level, $subject, $desc) { return ulldpd_notify_send($level, $subject, $desc); };
    foreach ($events as $ev) {
        if (!ulldpd_rl_allow($state, $now, $ev['if'] . '|' . $ev['kind'])) continue;
        list($subject, $desc) = ulldpd_event_text($ev);
        $send($cfg['NOTIFY_LEVEL'], $subject, $desc);
    }
    ulldpd_rl_summary($state, $now, $cfg['NOTIFY_LEVEL'], $send);
}

/* flash: only when something changed, and not more often than once a minute */
if ($state['meta']['dirty'] && $now - $state['meta']['boot_written'] >= ULLDPD_BOOT_INTERVAL) {
    if (ulldpd_state_save_boot($state)) { $state['meta']['dirty'] = false; $state['meta']['boot_written'] = $now; }
}
ulldpd_state_save_ram($state);
exit(0);
