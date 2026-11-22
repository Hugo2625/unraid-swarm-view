<?php
/* Swarm View test bench – router for `php -S 127.0.0.1:8090 -t /usr/local/emhttp server/router.php`
 * - /Docker?s=<scenario>  assembles the Docker tab like Unraid (PageBuilder) for that scenario
 * - mocks the backend endpoints of Unraid, FolderView 2/3, Compose Manager and Swarm View
 * - everything else: static files from the fake web root */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE);
define('BENCH', dirname(__DIR__));
require __DIR__ . '/scenario.php';

$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$S    = svtb_scenario();
svtb_log($uri);

/* ---------- Docker tab ---------- */
if ($uri === '/' || $uri === '/Docker') {
    if (isset($_GET['s'])) { setcookie('svtb', $_GET['s'], 0, '/'); $_COOKIE['svtb'] = $_GET['s']; }
    $w = (string)max(1, min(6, (int)($_GET['w'] ?? 1)));
    setcookie('svtb_w', $w, 0, '/'); $_COOKIE['svtb_w'] = $w;
    $S = svtb_scenario();
    require __DIR__ . '/page.php';
    return true;
}

/* ---------- Swarm View settings page (real page; docker = backend mock with 4 nodes) ---------- */
if ($uri === '/Settings/SwarmView') {
    if (isset($_GET['s'])) { setcookie('svtb', $_GET['s'], 0, '/'); $_COOKIE['svtb'] = $_GET['s']; $S = svtb_scenario(); }
    putenv('SV_DOCKER=' . BENCH . '/backend/docker-mock.py');
    putenv('MOCK_MODE=multi');
    svtb_log_fixture();
    $SVTB_PAGE = 'SwarmViewSettings';
    require __DIR__ . '/page.php';
    return true;
}
if ($uri === '/plugins/swarm-view/include/log.php') {
    require '/usr/local/emhttp/plugins/swarm-view/include/log.php';
    return true;
}

/** Example log for the settings page (written once per scenario) */
function svtb_log_fixture(): void {
    $f = getenv('SV_STATE') . '/swarm-view.log';
    if (is_file($f) && filesize($f) > 0 && empty($_GET['newlog'])) return;
    $now = time();
    $E = [
        [-3500, 'info', 'settings', 'Settings saved.', "REBALANCE: no → yes\nLOG_LEVEL: info → debug"],
        [-3400, 'info', 'action', 'Stack Up media', "Updating service media_lidarr (id: x1)\nUpdating service media_navidrome (id: x2)"],
        [-3000, 'warn', 'watchdog', 'Node raspberrypi is offline (before: online).'],
        [-2990, 'info', 'watchdog', 'media_navidrome now runs on Unraid (Before: raspberrypi)'],
        [-2980, 'warn', 'watchdog', 'Notification: Node raspberrypi is offline', 'Swarm cannot reach raspberrypi anymore. Services without a fixed node are moved to other nodes.'],
        [-2400, 'info', 'watchdog', 'Node raspberrypi is online (before: offline).'],
        [-2100, 'debug', 'watchdog', 'media_navidrome waits for raspberrypi: node stable for 5 of 5 minutes.'],
        [-2040, 'info', 'watchdog', 'media_navidrome is moved back to the preferred node raspberrypi.'],
        [-1980, 'info', 'watchdog', 'media_navidrome is back on raspberrypi.'],
        [-1500, 'info', 'watchdog', 'media_audnexus_api is moved back to the preferred node Unraid.'],
        [-1140, 'warn', 'watchdog', 'media_audnexus_api does not start on Unraid – return cancelled, Swarm runs it elsewhere again. Next attempt in 30 minutes.', 'starting container failed: Bind for 0.0.0.0:3100 failed: port is already allocated'],
        [-900, 'error', 'action', 'Update media_metube – failed', "Update failed\nError response from daemon: manifest unknown"],
        [-600, 'info', 'action', 'Placement of smarthome_esphome: prefer Unraid'],
        [-300, 'info', 'watchdog', 'Update check: media_metube, smarthome_mosquitto'],
        [-120, 'debug', 'watchdog', 'Watchdog run: 4 nodes, 21 services, 0.8 s.'],
        [-60, 'debug', 'watchdog', 'Watchdog run: 4 nodes, 21 services, 0.7 s.'],
    ];
    $out = '';
    foreach ($E as $e) $out .= json_encode(['t' => $now + $e[0], 'l' => $e[1], 's' => $e[2], 'm' => $e[3]] + (isset($e[4]) ? ['d' => $e[4]] : []), JSON_UNESCAPED_UNICODE) . "\n";
    @mkdir(dirname($f), 0777, true);
    file_put_contents($f, $out);
}

/* ---------- Unraid ---------- */
if ($uri === '/plugins/dynamix.docker.manager/include/DockerContainers.php') {
    if (($S['unraidList'] ?? '') === 'error') { http_response_code(500); return true; }
    require __DIR__ . '/rows.php';
    echo svtb_rows($S);
    return true;
}
if (preg_match('#^/plugins/dynamix\.docker\.manager/include/(UserPrefs|UpdateConfig|ContainerManager|Events)\.php$#', $uri)) { echo 'ok'; return true; }
if ($uri === '/webGui/include/Notify.php' || str_starts_with($uri, '/sub/') || str_starts_with($uri, '/pub/')) { echo ''; return true; }

/* ---------- Swarm View ---------- */
if ($uri === '/plugins/swarm-view/include/api.php') {
    $mode = $S['api'];
    if (!empty($S['apiDelay'])) usleep((int)$S['apiDelay'] * 1000);
    if ($mode === 'error500') { http_response_code(500); echo '<b>Fatal error</b>'; return true; }
    if ($mode === 'invalid')  { header('Content-Type: application/json'); echo '{"ok":tru'; return true; }
    if ($mode === 'php-warning') { header('Content-Type: application/json'); echo "<br />\n<b>Warning</b>: something in api.php<br />\n" . json_encode(svtb_api($S)); return true; }
    header('Content-Type: application/json');
    echo json_encode(svtb_api($S), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return true;
}
if ($uri === '/plugins/swarm-view/include/actions.php') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'log' => 'test bench: ' . ($_POST['action'] ?? '?')]);
    return true;
}

/* ---------- FolderView 3 / 2 ---------- */
if (preg_match('#^/plugins/(folder\.view[23])/server/([a-z_]+)\.php$#', $uri, $m)) {
    require __DIR__ . '/folderview.php';
    svtb_folderview($m[1], $m[2], $S);
    return true;
}

/* ---------- Compose Manager ---------- */
if (str_starts_with($uri, '/plugins/compose.manager/php/') && $uri !== '/plugins/compose.manager/php/compose_manager_main.php') {
    echo json_encode(['result' => 'success']);
    return true;
}

/* ---------- assets the plugins ship only in their release packages ---------- */
if (preg_match('#^/plugins/compose\.manager/javascript/ace/(.+)$#', $uri, $m)) { header('Content-Type: application/javascript'); readfile("/usr/local/emhttp/webGui/javascript/ace/{$m[1]}"); return true; }
if (str_starts_with($uri, '/plugins/compose.manager/javascript/sweetalert/')) {
    header('Content-Type: ' . (str_ends_with($uri, '.css') ? 'text/css' : 'application/javascript'));
    echo str_ends_with($uri, '.css') ? '' : 'window.Swal = window.Swal || {fire: function () { return Promise.resolve({}); }};';
    return true;
}
if (str_starts_with($uri, '/webGui/images/') && !is_file('/usr/local/emhttp' . $uri)) { header('Content-Type: image/png'); readfile('/usr/local/emhttp/plugins/dynamix.docker.manager/images/question.png'); return true; }
if ($uri === '/graphql') { http_response_code(404); return true; }

/* ---------- bench helpers ---------- */
if ($uri === '/svtb/log') { header('Content-Type: text/plain'); echo @file_get_contents(svtb_dir($S) . '/requests.log'); return true; }

return false; // static file
