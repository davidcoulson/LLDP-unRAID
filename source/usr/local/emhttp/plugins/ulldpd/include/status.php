<?PHP
/* LLDP status/neighbor endpoint for the settings page and the dashboard tile.
 * Read-only. Returns JSON containing server-side escaped HTML fragments.
 *   what = all (default) | tile
 * Called with POST + csrf_token by the page (checked by Unraid's prepend file
 * and explicitly below); a GET is also answered since nothing is changed.
 */
require_once '/usr/local/emhttp/plugins/ulldpd/include/ulldpd_config.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) ulldpd_json_exit(405, ['error' => 'method not allowed']);
if ($method === 'POST' && !ulldpd_csrf_ok()) ulldpd_json_exit(403, ['error' => 'bad csrf_token']);

$what = $_REQUEST['what'] ?? 'all';
if (!is_string($what) || !in_array($what, ['all', 'tile'], true)) ulldpd_json_exit(400, ['error' => 'invalid parameter']);

$snap = ulldpd_snapshot($what === 'all');
$out = [
    'running' => $snap['running'],
    'count'   => $snap['count'],
    'status'  => ulldpd_status_html($snap),
];
if ($what === 'all') {
    list($hist) = ulldpd_state_load();
    $out['neighbors'] = $snap['running'] ? ulldpd_neighbors_html($snap['rows'], $hist) : '<em>The LLDP service is not running.</em>';
    $out['history']   = ulldpd_history_html($hist);
    $out['chassis']   = $snap['running'] ? ulldpd_chassis_html($snap['chassis']) : '<em>The LLDP service is not running.</em>';
} else {
    $out['rows'] = $snap['running'] ? ulldpd_tile_rows_html($snap['rows']) : '<tr class="ulldpd-row"><td><em>LLDP service is not running.</em></td></tr>';
}
ulldpd_json_exit(200, $out);
