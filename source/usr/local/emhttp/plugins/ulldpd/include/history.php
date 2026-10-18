<?PHP
/* Neighbour history endpoint. POST + CSRF only.
 *   action = clear   forget the recorded neighbour changes
 */
require_once '/usr/local/emhttp/plugins/ulldpd/include/ulldpd_config.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ulldpd_json_exit(405, ['error' => 'POST required']);
if (!ulldpd_csrf_ok()) ulldpd_json_exit(403, ['error' => 'bad csrf_token']);
$action = $_POST['action'] ?? '';
if (!is_string($action) || $action !== 'clear') ulldpd_json_exit(400, ['error' => 'invalid parameter']);

$lock = ulldpd_lock();
if (!$lock) ulldpd_json_exit(500, ['error' => 'cannot lock the history']);
$ok = true;
if (file_exists(ULLDPD_HISTORY_FILE)) $ok = @unlink(ULLDPD_HISTORY_FILE);
if (file_exists(ULLDPD_STATE_FILE)) $ok = @unlink(ULLDPD_STATE_FILE) && $ok;
if (!$ok) ulldpd_json_exit(500, ['error' => 'cannot remove the history']);
ulldpd_json_exit(200, ['ok' => true, 'message' => 'Neighbor history cleared.', 'history' => ulldpd_history_html(ulldpd_hist_empty())]);
