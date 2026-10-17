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
const ULLDPD_CFG_FILE    = "/boot/config/plugins/ulldpd/ulldpd.cfg";
const ULLDPD_CUSTOM_FILE = "/boot/config/plugins/ulldpd/custom.conf";
const ULLDPD_CUSTOM_MAX  = 16384;   /* bytes; keep in sync with rc.lldpd */
const ULLDPD_PIDFILE     = "/run/lldpd.pid";
const ULLDPD_LLDPCLI     = "/usr/sbin/lldpcli";
const ULLDPD_LLDPD       = "/usr/sbin/lldpd";
const ULLDPD_VARINI      = "/var/local/emhttp/var.ini";

/* Settings schema. Every value from the cfg file is validated against these
 * patterns (the same ones rc.lldpd uses); an invalid value falls back to the
 * default. The cfg is parsed as data and never executed. */
const ULLDPD_BOOL_KEYS = ['SERVICE', 'FORCE', 'CDP', 'FDP', 'SONMP', 'EDP', 'RXONLY', 'IFALIAS'];
const ULLDPD_DEFAULTS  = [
    'SERVICE' => 'disable', 'FORCE' => 'disable', 'CDP' => 'disable', 'FDP' => 'disable',
    'SONMP' => 'disable', 'EDP' => 'disable', 'RXONLY' => 'disable', 'IFALIAS' => 'disable',
    'IFACES' => 'eth*', 'MGMT_PATTERN' => '', 'CHASSIS_IFACE' => '', 'SYSNAME' => '',
    'SYSDESCR' => '', 'TX_INTERVAL' => '30', 'TX_HOLD' => '4',
];
/* Anchored with \A..\z (no trailing-newline allowance) */
const ULLDPD_PATTERNS  = [
    'IFACES'        => '/\A[A-Za-z0-9_.*,!-]{1,128}\z/',
    'CHASSIS_IFACE' => '/\A[A-Za-z0-9_.*,!-]{0,128}\z/',
    'MGMT_PATTERN'  => '/\A[0-9A-Fa-f.:\/*,!]{0,256}\z/',
    'SYSNAME'       => '/\A[A-Za-z0-9._-]{0,63}\z/',
    'SYSDESCR'      => '/\A[A-Za-z0-9 ._,:\/()+@%=~*\[\]-]{0,200}\z/',
];

/* Is $val an acceptable value for setting $key? */
function ulldpd_valid($key, $val) {
    if (!is_string($val)) return false;
    if (in_array($key, ULLDPD_BOOL_KEYS, true)) return $val === 'enable' || $val === 'disable';
    if ($key === 'TX_INTERVAL') return preg_match('/\A[0-9]{1,4}\z/', $val) && (int)$val >= 1 && (int)$val <= 3600;
    if ($key === 'TX_HOLD')     return preg_match('/\A[0-9]{1,3}\z/', $val) && (int)$val >= 1 && (int)$val <= 100;
    if (isset(ULLDPD_PATTERNS[$key])) return preg_match(ULLDPD_PATTERNS[$key], $val) === 1;
    return false;
}

/* HTML escape; "(" is also encoded so remote-controlled text can never form
 * Unraid's _(translation)_ markers. */
function ulldpd_h($v) {
    if (is_bool($v)) $v = $v ? 'yes' : 'no';
    if (!is_scalar($v)) return '';
    return str_replace('(', '&#40;', htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
}

/* Parse the cfg file as data: KEY="value" lines, whitelisted keys, validated values. */
function ulldpd_load_cfg($file = ULLDPD_CFG_FILE) {
    $cfg = ULLDPD_DEFAULTS;
    $lines = @file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) return $cfg;
    foreach ($lines as $line) {
        $line = rtrim($line, "\r");
        if (!preg_match('/\A\s*([A-Z_]+)\s*=\s*(.*?)\s*\z/', $line, $m)) continue;
        if (!array_key_exists($m[1], ULLDPD_DEFAULTS)) continue;
        $val = $m[2];
        if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && $val[strlen($val) - 1] === $val[0]) {
            $val = substr($val, 1, -1);
        }
        if (ulldpd_valid($m[1], $val)) $cfg[$m[1]] = $val;
    }
    if ($cfg['IFACES'] === '') $cfg['IFACES'] = 'eth*';
    return $cfg;
}

/* Whitelisted value for an enable/disable setting (anything else = 'disable'). */
function ulldpd_bool_option($cfg, $key) {
    $val = (is_array($cfg) && isset($cfg[$key])) ? trim((string)$cfg[$key]) : 'disable';
    return ($val === 'enable') ? 'enable' : 'disable';
}

/* Run a command given as an argv array (no shell), with a timeout. Returns
 * [exit code, stdout]; exit code -1 when it could not be run or timed out. */
function ulldpd_run(array $argv, $timeout = 4) {
    $p = @proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) return [-1, ''];
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $end = microtime(true) + $timeout;
    $timedout = false;
    while (true) {
        $out .= (string)stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        $st = proc_get_status($p);
        if (!$st['running']) break;
        if (microtime(true) > $end || strlen($out) > 4194304) { $timedout = true; proc_terminate($p, 9); break; }
        usleep(20000);
    }
    $out .= (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]);
    $rc = proc_close($p);
    if (isset($st) && !$st['running'] && $st['exitcode'] !== -1) $rc = $st['exitcode'];
    return [$timedout ? -1 : $rc, $out];
}

/* PID of the running lldpd (pid file + executable identity), or 0. */
function ulldpd_pid() {
    if (!is_readable(ULLDPD_PIDFILE)) return 0;
    $pid = (int)trim((string)@file_get_contents(ULLDPD_PIDFILE));
    if ($pid <= 1) return 0;
    $exe = @readlink("/proc/$pid/exe");
    if ($exe === false) return 0;
    $exe = preg_replace('/ \(deleted\)$/', '', $exe);
    return basename($exe) === 'lldpd' ? $pid : 0;
}

function ulldpd_is_running() {
    return ulldpd_pid() > 0;
}

/* lldpd version string ("1.0.22"), '' if unknown. */
function ulldpd_version() {
    if (!is_executable(ULLDPD_LLDPD)) return '';
    list($rc, $out) = ulldpd_run([ULLDPD_LLDPD, '-v'], 3);
    $out = trim($out);
    return ($rc === 0 && preg_match('/\A[0-9][0-9A-Za-z.+~-]{0,31}\z/', $out)) ? $out : '';
}

/* Decoded "lldpcli -f json show <what>" or null. */
function ulldpd_json($what) {
    if (!in_array($what, ['chassis', 'neighbors'], true)) return null;
    if (!is_executable(ULLDPD_LLDPCLI)) return null;
    list($rc, $out) = ulldpd_run([ULLDPD_LLDPCLI, '-f', 'json', 'show', $what]);
    if ($rc !== 0) return null;
    $json = json_decode($out, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
    return is_array($json) ? $json : null;
}

/* ---- normalisation of lldpcli JSON (all values are remote-controlled) ---- */

/* scalar -> short plain string (never an array) */
function ulldpd_str($v) {
    if (is_bool($v)) return $v ? 'yes' : 'no';
    if (!is_scalar($v)) return '';
    return mb_substr((string)$v, 0, 512, 'UTF-8');
}

/* value or list of values -> list of strings */
function ulldpd_strlist($v) {
    if ($v === null) return [];
    if (!is_array($v)) return [ulldpd_str($v)];
    $out = [];
    foreach ($v as $x) { $s = ulldpd_str($x); if ($s !== '') $out[] = $s; }
    return $out;
}

/* lldpcli JSON lists: a list of {name: body} objects, a {name: body} object, or a single body */
function ulldpd_named($v) {
    $out = [];
    if (!is_array($v)) return $out;
    foreach ($v as $k => $x) {
        if (is_int($k) && is_array($x)) {
            foreach ($x as $k2 => $body) if (is_array($body)) $out[] = [(string)$k2, $body];
        } elseif (is_array($x)) {
            $out[] = [(string)$k, $x];
        }
    }
    return $out;
}

/* chassis node -> [name, id, descr, [mgmt ips], [enabled caps]] */
function ulldpd_chassis_info($ch) {
    $name = '';
    $body = [];
    if (is_array($ch)) {
        if (isset($ch['id']) && is_array($ch['id'])) { $body = $ch; }
        else { $n = ulldpd_named($ch); if ($n) { $name = $n[0][0]; $body = $n[0][1]; } }
    }
    $caps = [];
    if (isset($body['capability']) && is_array($body['capability'])) {
        $list = isset($body['capability']['type']) ? [$body['capability']] : $body['capability'];
        foreach ($list as $c) {
            if (is_array($c) && !empty($c['enabled']) && $c['enabled'] !== 'off' && isset($c['type'])) $caps[] = ulldpd_str($c['type']);
        }
    }
    $id = '';
    if (isset($body['id']) && is_array($body['id'])) $id = ulldpd_str($body['id']['value'] ?? '');
    return [
        'name'  => ulldpd_str($name),
        'id'    => $id,
        'descr' => ulldpd_str($body['descr'] ?? ''),
        'mgmt'  => ulldpd_strlist($body['mgmt-ip'] ?? null),
        'caps'  => $caps,
    ];
}

/* Neighbour rows (one per received neighbour/interface). */
function ulldpd_neighbors() {
    $json = ulldpd_json('neighbors');
    $rows = [];
    if (!is_array($json) || !isset($json['lldp']['interface'])) return $rows;
    foreach (ulldpd_named($json['lldp']['interface']) as $ent) {
        list($ifname, $n) = $ent;
        $ci = ulldpd_chassis_info($n['chassis'] ?? []);
        $port = (isset($n['port']) && is_array($n['port'])) ? $n['port'] : [];
        $vlans = [];
        if (isset($n['vlan']) && is_array($n['vlan'])) {
            $vl = isset($n['vlan']['vlan-id']) ? [$n['vlan']] : $n['vlan'];
            foreach ($vl as $v) {
                if (!is_array($v) || !isset($v['vlan-id'])) continue;
                $t = ulldpd_str($v['vlan-id']);
                if (!empty($v['pvid'])) $t .= ' (PVID)';
                $vlans[] = $t;
            }
        }
        $rows[] = [
            'iface'     => ulldpd_str($ifname),
            'via'       => ulldpd_str($n['via'] ?? ''),
            'name'      => $ci['name'],
            'chassis'   => $ci['id'],
            'port'      => (isset($port['id']) && is_array($port['id'])) ? ulldpd_str($port['id']['value'] ?? '') : '',
            'portdescr' => ulldpd_str($port['descr'] ?? ''),
            'vlans'     => $vlans,
            'mgmt'      => $ci['mgmt'],
            'age'       => ulldpd_str($n['age'] ?? ''),
        ];
    }
    return $rows;
}

/* Local chassis info or null. */
function ulldpd_local_chassis() {
    $json = ulldpd_json('chassis');
    if (!is_array($json) || !isset($json['local-chassis']['chassis'])) return null;
    return ulldpd_chassis_info($json['local-chassis']['chassis']);
}

/* ---- HTML rendering (everything escaped) ---- */

function ulldpd_join_h(array $items, $sep = '<br>') {
    return implode($sep, array_map('ulldpd_h', $items));
}

function ulldpd_neighbors_html(array $rows) {
    if (!$rows) return '<table class="unraid tablesorter ulldpd-table"><tbody><tr><td><em>No LLDP neighbors detected.</em></td></tr></tbody></table>';
    $h = '<table class="unraid tablesorter ulldpd-table"><thead><tr><th>Interface</th><th>Protocol</th><th>System name</th><th>Chassis ID</th><th>Port ID</th><th>Port description</th><th>VLAN(s)</th><th>Management IP(s)</th><th>Age</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr><td>' . ulldpd_h($r['iface']) . '</td><td>' . ulldpd_h($r['via']) . '</td><td>' . ulldpd_h($r['name'])
            . '</td><td>' . ulldpd_h($r['chassis']) . '</td><td>' . ulldpd_h($r['port']) . '</td><td>' . ulldpd_h($r['portdescr'])
            . '</td><td>' . ulldpd_join_h($r['vlans'], ', ') . '</td><td>' . ulldpd_join_h($r['mgmt']) . '</td><td>' . ulldpd_h($r['age']) . '</td></tr>';
    }
    return $h . '</tbody></table>';
}

function ulldpd_chassis_html($c) {
    if (!is_array($c)) return '<table class="unraid tablesorter ulldpd-table"><tbody><tr><td><em>No information available.</em></td></tr></tbody></table>';
    return '<table class="unraid tablesorter ulldpd-table"><thead><tr><th>System name</th><th>Chassis ID</th><th>Description</th><th>Management IP(s)</th><th>Enabled capabilities</th></tr></thead><tbody><tr><td>'
        . ulldpd_h($c['name']) . '</td><td>' . ulldpd_h($c['id']) . '</td><td>' . ulldpd_h($c['descr']) . '</td><td>'
        . ulldpd_join_h($c['mgmt']) . '</td><td>' . ulldpd_join_h($c['caps'], ', ') . '</td></tr></tbody></table>';
}

/* Dashboard tile rows: local interface -> switch / port */
function ulldpd_tile_rows_html(array $rows) {
    if (!$rows) return '<tr class="ulldpd-row"><td><em>No LLDP neighbors detected.</em></td></tr>';
    $h = '';
    foreach ($rows as $r) {
        $sw = $r['name'] !== '' ? $r['name'] : $r['chassis'];
        $port = $r['portdescr'] !== '' && $r['portdescr'] !== $r['port'] ? $r['port'] . ' (' . $r['portdescr'] . ')' : $r['port'];
        $h .= '<tr class="ulldpd-row"><td><span class="w26">' . ulldpd_h($r['iface']) . '</span><span class="w72">'
            . ulldpd_h($sw) . ' / ' . ulldpd_h($port) . '</span></td></tr>';
    }
    return $h;
}

function ulldpd_status_html(array $st) {
    if ($st['running']) {
        $s = '<span class="green-text"><i class="fa fa-circle"></i> Running</span> (pid ' . ulldpd_h($st['pid']) . ')';
    } else {
        $s = '<span class="red-text"><i class="fa fa-circle"></i> Stopped</span>';
    }
    return $s . ' &middot; Neighbors: ' . ulldpd_h($st['count']) . ' &middot; lldpd ' . ($st['version'] !== '' ? ulldpd_h($st['version']) : 'n/a');
}

/* Full status snapshot used by the page, the AJAX endpoint and the dashboard tile. */
function ulldpd_snapshot($withChassis = true) {
    $pid = ulldpd_pid();
    $rows = $pid ? ulldpd_neighbors() : [];
    return [
        'running'  => $pid > 0,
        'pid'      => $pid,
        'version'  => ulldpd_version(),
        'count'    => count($rows),
        'rows'     => $rows,
        'chassis'  => ($pid && $withChassis) ? ulldpd_local_chassis() : null,
    ];
}

/* CSRF: the POST token must match the one in var.ini (the Unraid prepend file
 * checks this too; this is the explicit defence in depth for our endpoints). */
function ulldpd_csrf_ok() {
    $var = @parse_ini_file(ULLDPD_VARINI);
    if (!is_array($var) || !isset($var['csrf_token']) || $var['csrf_token'] === '') return false;
    $tok = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($tok) && hash_equals((string)$var['csrf_token'], $tok);
}

function ulldpd_json_exit($code, $data) {
    http_response_code($code);
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

$ulldpd_cfg                 = ulldpd_load_cfg();
$ulldpd_service             = $ulldpd_cfg['SERVICE'];
$ulldpd_force               = $ulldpd_cfg['FORCE'];
$ulldpd_cdp                 = $ulldpd_cfg['CDP'];
$ulldpd_fdp                 = $ulldpd_cfg['FDP'];
$ulldpd_sonmp               = $ulldpd_cfg['SONMP'];
$ulldpd_edp                 = $ulldpd_cfg['EDP'];

$ulldpd_running             = ulldpd_is_running();
$ulldpd_installed_backend   = "";
foreach ((glob('/var/log/packages/lldpd-*') ?: []) as $ulldpd_pkg) {
    $ulldpd_installed_backend = htmlspecialchars(basename($ulldpd_pkg), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
