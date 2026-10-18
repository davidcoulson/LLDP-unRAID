<?PHP
/* Ports: detection of the local NICs / bonds (from /sys/class/net and
 * /proc/net/bonding), the settings-page Ports table, and the checks that compare
 * a neighbour's advertised 802.3 values with the local link.
 * Everything read from the system is validated and bounded; everything that is
 * printed goes through ulldpd_h().
 */

/* Same matching rules as lldpd's interface pattern ("eth*,!eth2", "!!" = always allow). */
function ulldpd_iface_selected($name, $patterns) {
    $denied = false;
    $found = false;
    foreach (explode(',', (string)$patterns) as $p) {
        if ($p === '') continue;
        if (strlen($p) >= 2 && $p[0] === '!' && $p[1] === '!' && fnmatch(substr($p, 2), $name)) return true;
        if ($p[0] === '!' && fnmatch(substr($p, 1), $name)) { $denied = true; $found = false; }
        elseif (!$denied && fnmatch($p, $name)) $found = true;
    }
    return $found;
}

function ulldpd_read_small($file, $max = 256) {
    $v = @file_get_contents($file, false, null, 0, $max);
    return is_string($v) ? trim($v) : '';
}

/* /proc/net/bonding/<bond> -> ['mode', 'mii', 'lacp_active', 'lacp_rate', 'agg', 'partner', 'slaves' => [name => [...]]] */
function ulldpd_bond_info($bond) {
    if (!ulldpd_ifname_valid($bond)) return null;
    $lines = @file('/proc/net/bonding/' . $bond, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines) || count($lines) > 2000) return null;
    $b = ['mode' => '', 'mii' => '', 'lacp_active' => '', 'lacp_rate' => '', 'agg' => '', 'partner' => '', 'slaves' => []];
    $cur = null;
    $inActive = false;
    foreach ($lines as $line) {
        if (!preg_match('/\A\s*([^:]{1,48}):\s*(.{0,120})\z/', $line, $m)) continue;
        $k = $m[1]; $v = trim($m[2]);
        if ($k === 'Slave Interface') { $cur = ulldpd_ifname_valid($v) ? $v : null; $inActive = false; if ($cur !== null) $b['slaves'][$cur] = ['mii' => '', 'speed' => '', 'agg' => '', 'churn' => '']; continue; }
        if ($cur === null) {
            if ($k === 'Bonding Mode') $b['mode'] = $v;
            elseif ($k === 'MII Status') $b['mii'] = $v;
            elseif ($k === 'LACP active') $b['lacp_active'] = $v;
            elseif ($k === 'LACP rate') $b['lacp_rate'] = $v;
            elseif ($k === 'Active Aggregator Info') $inActive = true;
            elseif ($inActive && $k === 'Aggregator ID') $b['agg'] = $v;
            elseif ($inActive && $k === 'Partner Mac Address') $b['partner'] = $v;
        } else {
            if ($k === 'MII Status') $b['slaves'][$cur]['mii'] = $v;
            elseif ($k === 'Speed') $b['slaves'][$cur]['speed'] = $v;
            elseif ($k === 'Aggregator ID') $b['slaves'][$cur]['agg'] = $v;
            elseif ($k === 'Actor Churn State') $b['slaves'][$cur]['churn'] = $v;
            elseif ($k === 'Partner Churn State') $b['slaves'][$cur]['pchurn'] = $v;
        }
    }
    foreach (['mode', 'mii', 'lacp_active', 'lacp_rate', 'agg', 'partner'] as $f) $b[$f] = mb_substr($b[$f], 0, 80, 'UTF-8');
    return $b;
}

/* Short bond mode ("802.3ad", "active-backup", ...) from the long /proc text. */
function ulldpd_bond_mode_short($mode) {
    if (stripos($mode, '802.3ad') !== false) return '802.3ad (LACP)';
    if (preg_match('/\A([a-z-]+)/i', $mode, $m)) return strtolower($m[1]) === 'fault-tolerance' ? 'active-backup' : $m[1];
    return $mode;
}

/* All local NICs and bond masters (no interface-pattern filter): name => info. */
function ulldpd_local_links() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $links = [];
    $bonds = [];
    foreach ((@scandir('/sys/class/net') ?: []) as $n) {
        if (!ulldpd_ifname_valid($n)) continue;
        $dir = "/sys/class/net/$n";
        $isBond = is_dir("$dir/bonding");
        $isNic = file_exists("$dir/device");
        if (!$isBond && !$isNic) continue;
        $speed = (int)ulldpd_read_small("$dir/speed", 16);
        $master = '';
        if (is_dir("$dir/bonding_slave") && is_link("$dir/master")) {
            $m = basename((string)@readlink("$dir/master"));
            if (ulldpd_ifname_valid($m)) $master = $m;
        }
        $links[$n] = [
            'name'   => $n,
            'kind'   => $isBond ? 'bond' : 'nic',
            'state'  => preg_replace('/[^a-z]/', '', ulldpd_read_small("$dir/operstate", 16)),
            'speed'  => $speed > 0 ? $speed : 0,    /* Mb/s */
            'mtu'    => (int)ulldpd_read_small("$dir/mtu", 16),
            'master' => $master,
            'bond'   => null,        /* bond master: parsed bond info */
            'slaves' => [],
        ];
        if ($isBond) $bonds[] = $n;
    }
    foreach ($bonds as $b) {
        $links[$b]['bond'] = ulldpd_bond_info($b);
        foreach ($links as $n => $l) if ($l['master'] === $b) $links[$b]['slaves'][] = $n;
    }
    ksort($links, SORT_NATURAL);
    return $cache = $links;
}

/* Detected ports shown in the settings table: NICs/bonds matching the interface pattern. */
function ulldpd_ports($iface_pattern) {
    $out = [];
    foreach (ulldpd_local_links() as $n => $l) if (ulldpd_iface_selected($n, $iface_pattern)) $out[$n] = $l;
    return $out;
}

/* One-line bond membership summary for a port ('' when not part of a bond). Plain text. */
function ulldpd_bond_summary(array $link, array $links) {
    if ($link['kind'] === 'bond') {
        $b = $link['bond'];
        $s = 'bond master' . ($b ? ', ' . ulldpd_bond_mode_short($b['mode']) : '');
        if ($link['slaves']) $s .= ', slaves ' . implode(' ', $link['slaves']);
        return $s;
    }
    if ($link['master'] === '') return '';
    $m = $link['master'];
    $s = 'slave of ' . $m;
    $b = isset($links[$m]) ? $links[$m]['bond'] : null;
    if (!$b) return $s;
    $s .= ', ' . ulldpd_bond_mode_short($b['mode']);
    if (stripos($b['mode'], '802.3ad') !== false) $s .= ', ' . ulldpd_lacp_state($b, $link['name']);
    return $s;
}

/* LACP state of slave $if in parsed bond $b, e.g. "LACP ok (aggregator 1)". */
function ulldpd_lacp_state(array $b, $if) {
    $sl = $b['slaves'][$if] ?? null;
    if (!$sl) return 'LACP state unknown';
    $ok = ($sl['mii'] === 'up') && ($sl['agg'] !== '') && ($sl['agg'] === $b['agg'])
        && (($sl['churn'] ?? '') === 'none' || ($sl['churn'] ?? '') === '');
    $s = $ok ? 'LACP ok' : 'LACP not in sync';
    if ($sl['agg'] !== '') $s .= ' (aggregator ' . $sl['agg'] . ($sl['agg'] !== $b['agg'] ? ', active is ' . $b['agg'] : '') . ')';
    if ($sl['mii'] !== '' && $sl['mii'] !== 'up') $s .= ', link ' . $sl['mii'];
    return $s;
}

/* "100 Gb/s" style link text */
function ulldpd_speed_text($mbps) {
    if ($mbps <= 0) return '';
    return $mbps >= 1000 ? rtrim(rtrim(number_format($mbps / 1000, 1, '.', ''), '0'), '.') . ' Gb/s' : $mbps . ' Mb/s';
}

/* Speed in Mb/s from an 802.3 MAU type text like "100GbaseCR10 - ..." / "1000BaseT" / "10GbaseT"; 0 if unknown. */
function ulldpd_mau_speed($mau) {
    if (!preg_match('/\A\s*(\d+)(p\d+)?\s*(G?)base/i', (string)$mau, $m)) return 0;
    $n = (int)$m[1];
    if ($m[2] !== '') $n = (float)($m[1] . '.' . substr($m[2], 1));
    return (int)round(strtoupper($m[3]) === 'G' ? $n * 1000 : $n);
}

/* Mismatches between what the neighbour advertises and the local link (plain-text list). */
function ulldpd_neighbor_warnings(array $row, array $links) {
    $w = [];
    $l = $links[$row['iface']] ?? null;
    if (!$l) return $w;
    $d = $row['detail'];
    if (preg_match('/\A\d{1,6}\z/', $d['mfs']) && $l['mtu'] > 0 && (int)$d['mfs'] < $l['mtu']) {
        $w[] = 'Remote max frame size (' . $d['mfs'] . ') is smaller than the local MTU (' . $l['mtu'] . '): large frames may be dropped.';
    }
    if ($d['autoneg'] !== null && $l['speed'] > 0) {
        $rs = ulldpd_mau_speed($d['autoneg']['current']);
        if ($rs > 0 && $rs !== $l['speed']) {
            $w[] = 'Remote operational speed (' . ulldpd_speed_text($rs) . ') differs from the local link speed (' . ulldpd_speed_text($l['speed']) . ').';
        }
    }
    if ($l['master'] !== '' && isset($links[$l['master']]) && $links[$l['master']]['bond'] && stripos($links[$l['master']]['bond']['mode'], '802.3ad') !== false) {
        if (strpos(ulldpd_lacp_state($links[$l['master']]['bond'], $row['iface']), 'not in sync') !== false) $w[] = 'LACP is not in sync on this bond member.';
    }
    return $w;
}

/* ---- settings page: Ports table (inside the settings form) ---- */

function ulldpd_ports_table_html(array $cfg) {
    $links = ulldpd_local_links();
    $ports = ulldpd_ports($cfg['IFACES']);
    $names = array_keys($ports);
    foreach (array_keys($cfg['ports']) as $n) if (!in_array($n, $names, true)) $names[] = $n;
    sort($names, SORT_NATURAL);
    if (!$names) return '<em>No network interfaces match the interface pattern.</em>';
    $h = '<table class="unraid tablesorter ulldpd-table ulldpd-ports"><thead><tr><th>Interface</th><th>Link</th><th>Bond / LACP</th><th>LLDP status</th><th>Port ID override</th><th>Port description override</th></tr></thead><tbody>';
    foreach ($names as $n) {
        $l = $ports[$n] ?? null;
        $c = $cfg['ports'][$n] ?? [];
        $st = $c['status'] ?? 'rx-and-tx';
        $link = $l ? ($l['state'] !== '' ? $l['state'] : 'unknown') . ($l['speed'] ? ', ' . ulldpd_speed_text($l['speed']) : '') . ($l['mtu'] ? ', MTU ' . $l['mtu'] : '') : 'not present / not matched';
        $bond = $l ? ulldpd_bond_summary($l, $links) : '';
        $h .= '<tr><td>' . ulldpd_h($n) . '</td><td>' . ulldpd_h($link) . '</td><td>' . ulldpd_h($bond) . '</td><td><select name="' . ulldpd_h(ulldpd_port_key('STATUS', $n)) . '" class="ulldpd-port-status" data-default="rx-and-tx">';
        foreach (ULLDPD_PORT_STATUSES as $s) $h .= '<option value="' . ulldpd_h($s) . '"' . ($s === $st ? ' selected' : '') . '>' . ulldpd_h($s === 'rx-and-tx' ? 'rx-and-tx (default)' : $s) . '</option>';
        $h .= '</select></td><td><input type="text" class="ulldpd-port-text" name="' . ulldpd_h(ulldpd_port_key('ID', $n)) . '" maxlength="64" style="width:12em" value="' . ulldpd_h($c['id'] ?? '') . '" placeholder="(default)"></td>'
            . '<td><input type="text" class="ulldpd-port-text" name="' . ulldpd_h(ulldpd_port_key('DESCR', $n)) . '" maxlength="100" style="width:16em" value="' . ulldpd_h($c['descr'] ?? '') . '" placeholder="(default)"></td></tr>';
    }
    return $h . '</tbody></table>';
}
