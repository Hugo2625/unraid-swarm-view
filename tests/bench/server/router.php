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
    $w = (string)max(1, min(3, (int)($_GET['w'] ?? 1)));
    setcookie('svtb_w', $w, 0, '/'); $_COOKIE['svtb_w'] = $w;
    $S = svtb_scenario();
    require __DIR__ . '/page.php';
    return true;
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
