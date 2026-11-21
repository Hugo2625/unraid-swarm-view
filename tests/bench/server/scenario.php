<?php
/* Scenario handling: scenarios/<name>.json (merged with defaults) → per-scenario config dir + mock data */

const SVTB_DEFAULTS = [
    'folderview' => 'fv3',        // fv3 | fv2 | none
    'compose'    => true,         // Compose Manager installed
    'position'   => 'bottom',     // Swarm section: top | middle | bottom
    'view'       => 'advanced',   // advanced | basic (Unraid cookie)
    'lang'       => 'en',         // Unraid display language (en | de)
    'theme'      => 'black',      // black | white | azure | gray
    'api'        => 'normal',     // normal | error500 | invalid | php-warning | swarm-inactive | worker | pi-down | no-stats | empty | many | no-local
    'apiDelay'   => 0,            // ms delay for api.php
    'unraidList' => 'ok',         // ok | error | empty
    'cfg'        => [],           // swarm-view.cfg overrides (e.g. HOST_COLUMN, REMOTE_ROWS, FOLDER_LOAD, ORPHAN_IMAGES)
    'folders'    => 'default',    // FolderView folder set: default | alias-regex | none | all-remote
    'locked'     => false,        // Unraid "unlock sortable" active
    'swarmview'  => true,         // false = baseline without Swarm View
    'workers'    => 1,            // 1–3 or 6 worker nodes besides Unraid (cookie svtb_w / ?w= overrides)
];

function svtb_scenario(): array {
    $name = preg_replace('/[^a-z0-9_\-]/i', '', $_COOKIE['svtb'] ?? 'default') ?: 'default';
    $file = BENCH . "/scenarios/$name.json";
    $s = is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    $s = array_replace(SVTB_DEFAULTS, $s);
    if (isset($_COOKIE['svtb_w']) && (int)$_COOKIE['svtb_w'] >= 1) $s['workers'] = min(6, (int)$_COOKIE['svtb_w']);
    $s['name'] = $name;
    svtb_prepare($s);
    return $s;
}

function svtb_dir(array $s): string {
    $d = "/tmp/svtb/{$s['name']}-w" . ($s['workers'] ?? 1);
    if (!is_dir($d)) @mkdir($d, 0777, true);
    return $d;
}

function svtb_log(string $uri): void {
    if (str_contains($uri, '.js') || str_contains($uri, '.css') || str_contains($uri, '.woff') || str_contains($uri, '.png')) return;
    $s = svtb_scenario_light() . '-w' . max(1, (int)($_COOKIE['svtb_w'] ?? 1));
    @mkdir("/tmp/svtb/$s", 0777, true);
    @file_put_contents("/tmp/svtb/$s/requests.log", date('H:i:s ') . $_SERVER['REQUEST_METHOD'] . " $uri\n", FILE_APPEND);
}
function svtb_scenario_light(): string { return preg_replace('/[^a-z0-9_\-]/i', '', $_COOKIE['svtb'] ?? 'default') ?: 'default'; }

/** Atomic write, skipped when unchanged – several PHP workers serve one scenario at the same time. */
function svtb_write(string $path, string $content): void {
    if (@file_get_contents($path) === $content) return;
    $tmp = $path . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, $content);
    rename($tmp, $path);
}

/** Writes the config files Swarm View and Unraid read for this scenario. */
function svtb_prepare(array $s): void {
    $d = svtb_dir($s);
    $pos = ['top' => 'top', 'middle' => 'middle', 'bottom' => 'bottom'][$s['position']] ?? 'bottom';
    $cfg = array_replace(['SWARM_POSITION' => $pos, 'LANGUAGE' => 'auto', 'STATS' => 'yes', 'HOST_COLUMN' => 'yes', 'REMOTE_ROWS' => 'always'], $s['cfg']);
    $txt = '';
    foreach ($cfg as $k => $v) $txt .= "$k=\"$v\"\n";
    svtb_write("$d/swarm-view.cfg", $txt);
    $rank = ['top' => 0, 'middle' => 1, 'bottom' => 3][$pos];
    svtb_write("$d/menu.cfg", "MENU=\"Docker:$rank\"\n");
    svtb_write("$d/dynamix.cfg", "[display]\nlocale=\"" . ($s['lang'] === 'de' ? 'de_DE' : '') . "\"\ntheme=\"{$s['theme']}\"\n");
    if (!is_dir("$d/state")) @mkdir("$d/state", 0777, true);
    $GLOBALS['SVTB_WORKERS'] = $s['workers'] ?? 1;
    putenv("SV_CFG=$d/swarm-view.cfg");
    putenv("SV_STATE=$d/state");
    putenv("SV_DYNAMIX_CFG=$d/dynamix.cfg");
    putenv("SV_MENU_CFG=$d/menu.cfg");
}

function svtb_world(int $workers = 0): array {
    static $cache = [];
    $n = $workers ?: max(1, min(6, (int)($GLOBALS['SVTB_WORKERS'] ?? 1)));
    if (!in_array($n, [1, 2, 3, 6], true)) $n = $n > 3 ? 6 : 3;
    $file = BENCH . '/scenarios/' . ($n === 1 ? 'world.json' : "world-w$n.json");
    return $cache[$n] ??= json_decode(file_get_contents($file), true);
}

/** api.php answer for the scenario */
function svtb_api(array $s): array {
    $a = svtb_world()['api'];
    $c = array_replace(['REMOTE_ROWS' => 'always', 'HOST_COLUMN' => 'yes', 'FOLDER_LOAD' => 'total', 'ORPHAN_IMAGES' => 'swarm',
                        'REBALANCE' => 'no', 'REBALANCE_DELAY' => '60', 'REFRESH' => '15'], $s['cfg']);
    $a['config'] = ['remoteRows' => $c['REMOTE_ROWS'], 'hostColumn' => $c['HOST_COLUMN'] === 'yes', 'folderLoad' => $c['FOLDER_LOAD'],
                    'orphans' => $c['ORPHAN_IMAGES'], 'rebalance' => $c['REBALANCE'] === 'yes', 'rebalanceDelay' => (int)$c['REBALANCE_DELAY'],
                    'refresh' => (int)$c['REFRESH']];
    $a['time'] = date('H:i:s');
    switch ($s['api']) {
        case 'swarm-inactive':
            $a['swarm'] = ['state' => 'inactive', 'manager' => false, 'nodeId' => '', 'historyLimit' => null, 'active' => false];
            $a['nodes'] = []; $a['stacks'] = []; $a['containers'] = (object)[]; $a['stats'] = ['enabled' => false, 'nodes' => (object)[], 'tasks' => (object)[]];
            $a['host'] = 'Unraid';
            break;
        case 'worker':
            $a['swarm'] = ['state' => 'active', 'manager' => false, 'nodeId' => 'n1', 'historyLimit' => null, 'active' => true];
            $a['nodes'] = []; $a['stacks'] = []; $a['containers'] = (object)[]; $a['stats'] = ['enabled' => false, 'nodes' => (object)[], 'tasks' => (object)[]];
            break;
        case 'pi-down':   // first worker unreachable: node down, its agent values stale, no container values from it
            foreach ($a['nodes'] as &$n) if ($n['hostname'] === 'raspberrypi') $n['state'] = 'down';
            unset($n);
            $a['stats']['nodes']['raspberrypi']['stale'] = true;
            $a['stats']['tasks'] = (object)array_filter((array)$a['stats']['tasks'], fn($t) => ($t['node'] ?? '') !== 'raspberrypi');
            foreach ($a['stacks'] as &$st) foreach ($st['services'] as &$sv) foreach ($sv['tasks'] as &$t)
                if ($t['node'] === 'raspberrypi') $t['current'] = 'Running 2 hours ago'; // swarm still reports them
            unset($st, $sv, $t);
            break;
        case 'no-stats':
            $a['stats'] = ['enabled' => false, 'nodes' => (object)[], 'tasks' => (object)[]];
            break;
        case 'empty':
            $a['stacks'] = []; $a['containers'] = (object)[]; $a['managed'] = []; $a['stackInfo'] = (object)[];
            $a['stats']['tasks'] = (object)[];
            break;
        case 'no-local': // nothing on Unraid – its tasks moved to the first worker
            foreach ($a['stacks'] as &$st) foreach ($st['services'] as &$sv) foreach ($sv['tasks'] as &$t) if ($t['local']) { $t['node'] = 'raspberrypi'; $t['local'] = false; }
            unset($st, $sv, $t);
            $a['containers'] = (object)[];
            break;
        case 'many':
            $base = $a['stacks'][1];
            for ($i = 1; $i <= 6; $i++) {
                $copy = $base; $copy['name'] = "bulk$i";
                foreach ($copy['services'] as &$sv) {
                    $sv['name'] = $sv['id'] = "bulk{$i}_{$sv['short']}"; $sv['stack'] = "bulk$i";
                    $workers = array_values(array_filter(array_column($a['nodes'], 'hostname'), fn($h) => $h !== 'Unraid'));
                    foreach ($sv['tasks'] as $k => &$t) { $t['id'] = substr(md5("bulk$i" . $t['id']), 0, 25); $t['node'] = $workers[($i + $k) % count($workers)]; $t['local'] = false; }
                    unset($t);
                }
                unset($sv);
                $a['stacks'][] = $copy;
            }
            break;
    }
    if (empty((array)$a['containers'])) $a['containers'] = (object)[];
    return $a;
}

/** Local containers for this scenario (what Unraid's DockerContainers.php sees) */
function svtb_local(array $s): array {
    if ($s['unraidList'] === 'empty') return [];
    $w = svtb_world();
    $api = svtb_api($s);
    $keep = array_keys((array)$api['containers']);
    // Swarm task containers exist on Unraid unless the scenario moved/removed them
    $dropTasks = in_array($s['api'], ['empty', 'no-local', 'swarm-inactive'], true);
    return array_values(array_filter($w['local'], function ($c) use ($dropTasks) {
        $isTask = isset($c['labels']['com.docker.swarm.task.id']);
        $isAgent = ($c['labels']['com.docker.swarm.service.name'] ?? '') === 'swarm-view-agent';
        return !(($isTask || $isAgent) && $dropTasks);
    }));
}
