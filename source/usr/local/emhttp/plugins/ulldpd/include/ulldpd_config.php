<?PHP
/* Copyright Derek Macias (parts of code from NUT package)
 * Copyright macester (parts of code from NUT package)
 * Copyright gfjardim (parts of code from NUT package)
 * Copyright SimonF (parts of code from NUT package)
 * Copyright desertwitch
 *
 * Copyright Dan Landon
 * Copyright Bergware International
 * Copyright Lime Technology
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License 2
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be
 * included in all copies or substantial portions of the Software.
 *
 */
const ULLDPD_CFG_FILE = "/boot/config/plugins/ulldpd/ulldpd.cfg";
const ULLDPD_PIDFILE  = "/run/lldpd.pid";
const ULLDPD_LLDPCLI  = "/usr/sbin/lldpcli";

/* Whitelisted value for an enable/disable setting (anything else = 'disable'). */
function ulldpd_bool_option($cfg, $key) {
    $val = (is_array($cfg) && isset($cfg[$key])) ? trim((string)$cfg[$key]) : 'disable';
    return ($val === 'enable') ? 'enable' : 'disable';
}

/* Daemon is running: pid file holds a live process whose executable is lldpd. */
function ulldpd_is_running() {
    if (!is_readable(ULLDPD_PIDFILE)) return false;
    $pid = (int)trim((string)@file_get_contents(ULLDPD_PIDFILE));
    if ($pid <= 1) return false;
    $exe = @readlink("/proc/$pid/exe");
    if ($exe === false) return false;
    $exe = preg_replace('/ \(deleted\)$/', '', $exe);
    return basename($exe) === 'lldpd';
}

/* Print "key: value" lines for any decoded lldpcli JSON; every string is escaped. */
function ulldpd_render($data, $indent = 0) {
    $out = '';
    $pad = str_repeat('  ', $indent);
    foreach ($data as $key => $val) {
        $label = is_int($key) ? '' : htmlspecialchars((string)$key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if (is_array($val)) {
            if ($label !== '') $out .= $pad . $label . ":\n";
            $out .= ulldpd_render($val, $label !== '' ? $indent + 1 : $indent);
            if ($label === '') $out .= "\n";
        } else {
            if (is_bool($val)) $val = $val ? 'true' : 'false';
            $text = htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $out .= $pad . ($label !== '' ? $label . ': ' : '') . $text . "\n";
        }
    }
    return $out;
}

/* Output of "lldpcli show <chassis|neighbors>" as HTML-safe text. */
function ulldpd_show($what) {
    if (!in_array($what, ['chassis', 'neighbors'], true)) return '';
    if (!is_executable(ULLDPD_LLDPCLI)) return 'lldpcli is not installed.';
    $lines = [];
    exec(escapeshellarg(ULLDPD_LLDPCLI) . ' -f json show ' . $what . ' 2>&1', $lines, $rc);
    $raw = implode("\n", $lines);
    $json = json_decode($raw, true);
    if ($rc === 0 && is_array($json)) {
        $text = ulldpd_render($json);
        return ($text === '') ? 'No information available.' : $text;
    }
    return htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$ulldpd_cfg                 = file_exists(ULLDPD_CFG_FILE) ? parse_ini_file(ULLDPD_CFG_FILE) : [];
if (!is_array($ulldpd_cfg)) $ulldpd_cfg = [];

$ulldpd_service             = ulldpd_bool_option($ulldpd_cfg, 'SERVICE');
$ulldpd_force               = ulldpd_bool_option($ulldpd_cfg, 'FORCE');
$ulldpd_cdp                 = ulldpd_bool_option($ulldpd_cfg, 'CDP');
$ulldpd_fdp                 = ulldpd_bool_option($ulldpd_cfg, 'FDP');
$ulldpd_sonmp               = ulldpd_bool_option($ulldpd_cfg, 'SONMP');
$ulldpd_edp                 = ulldpd_bool_option($ulldpd_cfg, 'EDP');

$ulldpd_running             = ulldpd_is_running();
$ulldpd_installed_backend   = "";
foreach ((glob('/var/log/packages/lldpd-*') ?: []) as $ulldpd_pkg) {
    $ulldpd_installed_backend = htmlspecialchars(basename($ulldpd_pkg), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
