<?PHP
/* Saves the advanced custom lldpd configuration (/boot/config/plugins/ulldpd/custom.conf).
 * POST only, CSRF-checked, size-limited. The content is lldpcli configuration
 * syntax (ASCII text, one "configure ..." / "unconfigure ..." command per line,
 * # comments); rc.lldpd copies it to /etc/lldpd.d/ when the service starts.
 */
require_once '/usr/local/emhttp/plugins/ulldpd/include/ulldpd_config.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ulldpd_json_exit(405, ['error' => 'POST required']);
if (!ulldpd_csrf_ok()) ulldpd_json_exit(403, ['error' => 'bad csrf_token']);

$content = $_POST['content'] ?? null;
if (!is_string($content)) ulldpd_json_exit(400, ['error' => 'missing content']);
$content = str_replace("\r\n", "\n", $content);
$content = str_replace("\r", "\n", $content);
if (strlen($content) > ULLDPD_CUSTOM_MAX) ulldpd_json_exit(413, ['error' => 'configuration is too large (limit ' . ULLDPD_CUSTOM_MAX . ' bytes)']);
if (preg_match('/[^\x09\x0A\x20-\x7E]/', $content)) ulldpd_json_exit(400, ['error' => 'only plain ASCII text is allowed']);
foreach (explode("\n", $content) as $n => $line) {
    if (!preg_match('/\A\s*(#.*|(un)?configure(\s.*)?)?\z/', $line)) {
        ulldpd_json_exit(400, ['error' => 'line ' . ($n + 1) . ': only "configure ...", "unconfigure ..." and # comments are allowed']);
    }
}

$dir = dirname(ULLDPD_CUSTOM_FILE);
if (!is_dir($dir) && !@mkdir($dir, 0755, true)) ulldpd_json_exit(500, ['error' => 'cannot create ' . $dir]);

if (trim($content) === '') {
    if (file_exists(ULLDPD_CUSTOM_FILE) && !@unlink(ULLDPD_CUSTOM_FILE)) ulldpd_json_exit(500, ['error' => 'cannot remove custom configuration']);
    ulldpd_json_exit(200, ['ok' => true, 'message' => 'Custom configuration removed.']);
}
if (substr($content, -1) !== "\n") $content .= "\n";

$tmp = ULLDPD_CUSTOM_FILE . '.tmp';
if (@file_put_contents($tmp, $content, LOCK_EX) !== strlen($content) || !@rename($tmp, ULLDPD_CUSTOM_FILE)) {
    @unlink($tmp);
    ulldpd_json_exit(500, ['error' => 'cannot write ' . ULLDPD_CUSTOM_FILE]);
}
ulldpd_json_exit(200, ['ok' => true, 'message' => 'Custom configuration saved; it is used when the service is (re)started.']);
