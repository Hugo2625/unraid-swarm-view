<?php
/* Assembles the Docker tab like Unraid: PageBuilder collects all .page files with Menu "Docker",
 * sorts them by rank and renders them stacked (MainContentTabless). Layout = Unraid's CSS + dynamix.js
 * + the minimal globals the pages expect. */
$docroot = '/usr/local/emhttp';
// $SVTB_PAGE (router): render this single page instead of the Docker tab (e.g. SwarmViewSettings)
if (empty($SVTB_PAGE)) putenv('SV_DOCKER=/bin/false');
// no shared files: the section rank comes from the scenario (see below), the language via SV_DYNAMIX_CFG (scenario.php)

// --- Unraid globals/helpers the pages use ---
require_once "$docroot/webGui/include/Wrappers.php";
require_once "$docroot/webGui/include/Helpers.php";
require_once "$docroot/webGui/include/PageBuilder.php";
if (!function_exists('includePageStylesheets')) {
    // Unraid 7.0: page stylesheets were included inline in DefaultPageLayout.php
    function includePageStylesheets(array $page): void {
        global $docroot, $theme;
        $css = "/{$page['root']}/sheets/{$page['name']}";
        foreach (["$css.css", "$css-$theme.css"] as $f) if (is_file($docroot . $f)) echo '<link type="text/css" rel="stylesheet" href="', autov($f), '">', "\n";
    }
}
if (!function_exists('_')) { function _($s, $o = 0) { return $s; } }
if (!function_exists('parse_text')) { function parse_text($t) { return preg_replace('/_\((.+?)\)_/m', '$1', $t); } }
if (!function_exists('my_disk')) { function my_disk($d) { return $d; } }
if (!function_exists('processTitle')) { function processTitle($t) { return $t; } }
if (!function_exists('cpu_list')) { function cpu_list() { return ['0,6', '1,7', '2,8', '3,9', '4,10', '5,11']; } }
$var = ['fsState' => 'Started', 'csrf_token' => 'TESTBENCH', 'NAME' => 'Unraid', 'regTy' => 'Pro', 'mdState' => 'STARTED'];
$display = ['theme' => $S['theme'], 'locale' => $S['lang'] === 'de' ? 'de_DE' : '', 'resize' => 0, 'tabs' => 1, 'unit' => 'C', 'number' => '.,'];
$dockercfg = ['DOCKER_READMORE' => 'yes', 'DOCKER_ENABLED' => 'yes'];
$pools = []; $disks = []; $devs = []; $users = []; $shares = []; $sec = []; $sec_nfs = []; $name = ''; $pool_devices = false;
$theme = $S['theme'];
$themeHelper = new class($theme) {
    public function __construct(private string $t) {}
    public function isTopNavTheme(): bool { return in_array($this->t, ['black', 'white'], true); }
    public function isSidebarTheme(): bool { return !$this->isTopNavTheme(); }
    public function getThemeName(): string { return $this->t; }
    public function __call($n, $a) { return false; }
};

// --- pages of the Docker tab (only the plugins of this scenario) ---
$site = [];
chdir($docroot);   // Unraid builds pages with paths relative to the web root
build_pages("plugins/dynamix.docker.manager/*.page");
if ($S['swarmview'] ?? true) build_pages("plugins/swarm-view/*.page");
if ($S['compose']) build_pages("plugins/compose.manager/*.page");
if ($S['folderview'] === 'fv3') build_pages("plugins/folder.view3/*.page");
if ($S['folderview'] === 'fv2') build_pages("plugins/folder.view2/*.page");
foreach ($site as &$p) unset($p['Cond']); unset($p);
// Swarm View reads its rank from /boot/config/plugins/swarm-view/menu.cfg – use the scenario's copy instead,
// so parallel requests of different scenarios do not overwrite each other
if (isset($site['SwarmView'])) {
    $mc = @parse_ini_file(svtb_dir($S) . '/menu.cfg');
    $site['SwarmView']['Menu'] = $mc['MENU'] ?? 'Docker:3';
}   // the scenario decides which plugins are installed
$pages = [];
if (!empty($SVTB_PAGE)) $pages[$SVTB_PAGE] = $site[$SVTB_PAGE];
else foreach (find_pages('Docker') as $k => $p) $pages[$k] = $p;   // no Cond evaluation – the scenario decides
$order = array_map(fn($p) => $p['name'], $pages);

function svtb_content(array $page): string {
    // Unraid translates _(text)_ while building the page
    return preg_replace_callback('/_\((.+?)\)_/m', fn($m) => $m[1], $page['text']);
}
$cookieView = $S['view'] === 'advanced' ? 'advanced' : 'basic';
?><!DOCTYPE html>
<html lang="<?= $S['lang'] ?>"><head><meta charset="utf-8"><title>Docker – <?= htmlspecialchars($S['name']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php
// the stylesheets of THIS webgui version, in its order (7.0: default-<theme>/dynamix-<theme>, 7.1+: themes/<theme> …)
preg_match_all('#autov\("(/webGui/styles/[^"]+\.css)"\)#', (string)@file_get_contents("$docroot/webGui/include/DefaultPageLayout.php"), $m);
$sheets = array_unique(array_map(fn($f) => str_replace(['{$theme}', '$theme', "{\$display['theme']}"], $theme, $f), $m[1]));
if (!$sheets) $sheets = array_map(fn($c) => "/webGui/styles/$c.css", ['default-fonts', 'default-cases', 'font-awesome', 'context.standalone', 'jquery.sweetalert', 'jquery.ui', 'default-color-palette', 'default-base', 'default-dynamix', "themes/$theme"]);
foreach ($sheets as $css): ?>
<link type="text/css" rel="stylesheet" href="<?php autov($css) ?>">
<?php endforeach; ?>
<?php foreach ($pages as $page) includePageStylesheets($page); ?>
<script>
document.cookie = 'docker_listview_mode=<?= $cookieView ?>; path=/';
<?php if ($S['locked']): ?>document.cookie = 'lockbutton=lockbutton; path=/';<?php else: ?>document.cookie = 'lockbutton=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';<?php endif; ?>
window.SVTB = {scenario: <?= json_encode($S) ?>, pages: <?= json_encode(array_values($order)) ?>, errors: []};
window.addEventListener('error', e => SVTB.errors.push((e.message || 'error') + ' @ ' + (e.filename || '').split('/').pop() + ':' + e.lineno));
window.addEventListener('unhandledrejection', e => SVTB.errors.push('unhandled rejection: ' + (e.reason && (e.reason.message || e.reason.statusText || e.reason))));
</script>
<script src="<?php autov('/webGui/javascript/dynamix.js') ?>"></script>
<script src="<?php autov('/webGui/javascript/translate.en_US.js') ?>"></script>
<script>
// minimal stand-ins for HeadInlineJS (DefaultPageLayout)
var csrf_token = "TESTBENCH";
const timers = {};
$.ajaxPrefilter(function (s, o, x) { if (s.type && s.type.toUpperCase() === 'POST') s.data = (s.data ? s.data + '&' : '') + 'csrf_token=' + csrf_token; });
function openDocker(cmd, title, plg, func) { SVTB.opened = (SVTB.opened || []).concat([cmd]); if (func && window[func]) setTimeout(window[func], 10); }
function openTerminal() {} function openBox() {} function openWindow() {} function openPlugin() {}
function addBannerWarning() {} function removeBannerWarning() {} function showFooter() {} function showNotice() {}
function fillAvailableHeight() {}
// Nchan stand-in: tests can push messages with SVTB.nchan['/sub/dockerload'].emit(text)
SVTB.nchan = {};
window.NchanSubscriber = function (url) { this.url = url.split(',')[0]; this.h = {}; SVTB.nchan[this.url] = this; };
NchanSubscriber.prototype.on = function (ev, fn) { (this.h[ev] = this.h[ev] || []).push(fn); return this; };
NchanSubscriber.prototype.addEventListener = NchanSubscriber.prototype.on;
NchanSubscriber.prototype.start = function () { this.running = true; return this; };
NchanSubscriber.prototype.monitor = function () { return this; };   // 7.1: dockerload.start().monitor()
NchanSubscriber.prototype.stop = function () { this.running = false; return this; };
NchanSubscriber.prototype.emit = function (msg) { (this.h.message || []).forEach(fn => fn(msg)); };
function pauseEvents() {} function resumeEvents() {}
</script>
</head>
<body class="svtb page-docker">
<div id="template"><div id="displaybox"><div class="content">
<div class="tabs"></div>
<?php foreach ($pages as $page): ?>
<!-- page: <?= $page['name'] ?> -->
<?php if (isset($page['Title'])): ?>
<div class="title"><?= tab_title(processTitle($page['Title']), $page['root'], $page['Tag'] ?? false) ?><span class="right inline-flex flex-row items-center gap-1"></span></div>
<?php endif; ?>
<?php
    try { eval('?>' . svtb_content($page)); }
    catch (\Throwable $e) { echo "<pre class='svtb-php-error'>PHP in {$page['name']}: " . htmlspecialchars($e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()) . "</pre>"; }
?>
<?php endforeach; ?>
</div></div></div>
<div class="spinner fixed" style="display:none"></div>
</body></html>
