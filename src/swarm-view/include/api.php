<?php
/*
 * Swarm View – read-only API.
 * Returns swarm state, nodes, stacks/services/tasks and local non-swarm containers as JSON.
 * Aufruf: GET /plugins/swarm-view/include/api.php
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/common.php';
$errors = [];

/** Runs a docker command. Returns stdout or null (the error text goes to $errors). */
function dk(string $args, string $ctx): ?string {
    global $DOCKER, $errors;
    $out = [];
    $rc = 0;
    exec(escapeshellcmd($DOCKER) . ' ' . $args . ' 2>&1', $out, $rc);
    $txt = implode("\n", $out);
    if ($rc !== 0) {
        $errors[] = "$ctx: " . trim($txt);
        return null;
    }
    return $txt;
}

/** Parses output of --format '{{json .}}' (one JSON object per line). */
function jsonLines(?string $txt): array {
    if ($txt === null) return [];
    $rows = [];
    foreach (explode("\n", $txt) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $d = json_decode($line, true);
        if (is_array($d)) $rows[] = $d;
    }
    return $rows;
}

function jsonDoc(?string $txt): array {
    if ($txt === null) return [];
    $d = json_decode($txt, true);
    return is_array($d) ? $d : [];
}

/** "a=b,c=d" from `docker ps` → map. Commas in values are kept as long as no "key=" follows. */
function parseLabels(string $s): array {
    $map = [];
    if ($s === '') return $map;
    foreach (preg_split('/,(?=[A-Za-z0-9_.\-\/]+=)/', $s) as $kv) {
        $p = strpos($kv, '=');
        if ($p === false) continue;
        $map[substr($kv, 0, $p)] = substr($kv, $p + 1);
    }
    return $map;
}

function args(array $ids): string {
    return implode(' ', array_map('escapeshellarg', $ids));
}

$hostname = gethostname() ?: 'Unraid';

/* ---------- Swarm-Status ---------- */
$info = jsonDoc(dk("info --format '{{json .Swarm}}'", 'docker info'));
$swarm = [
    'state'   => $info['LocalNodeState'] ?? 'unknown',
    'manager' => !empty($info['ControlAvailable']),
    'nodeId'  => $info['NodeID'] ?? '',
    'historyLimit' => $info['Cluster']['Spec']['Orchestration']['TaskHistoryRetentionLimit'] ?? null,
];
$swarm['active'] = $swarm['state'] === 'active';

$nodes  = [];
$stacks = [];
$selfHost = $hostname;

if ($swarm['active'] && $swarm['manager']) {
    /* ---------- Nodes ---------- */
    $nodeIds = array_column(jsonLines(dk("node ls --format '{{json .}}'", 'node ls')), 'ID');
    if ($nodeIds) {
        foreach (jsonDoc(dk('node inspect ' . args($nodeIds), 'node inspect')) as $n) {
            $isSelf = ($n['ID'] ?? '') === $swarm['nodeId'];
            $h = $n['Description']['Hostname'] ?? ($n['ID'] ?? '?');
            if ($isSelf) $selfHost = $h;
            $nodes[] = [
                'id'           => $n['ID'] ?? '',
                'hostname'     => $h,
                'self'         => $isSelf,
                'role'         => $n['Spec']['Role'] ?? '',
                'availability' => $n['Spec']['Availability'] ?? '',
                'state'        => $n['Status']['State'] ?? '',
                'addr'         => $n['Status']['Addr'] ?? '',
                'leader'       => !empty($n['ManagerStatus']['Leader']),
                'arch'         => $n['Description']['Platform']['Architecture'] ?? '',
                'cpus'         => round(($n['Description']['Resources']['NanoCPUs'] ?? 0) / 1e9, 1),
                'memGB'        => round(($n['Description']['Resources']['MemoryBytes'] ?? 0) / 1073741824, 1),
                'engine'       => $n['Description']['Engine']['EngineVersion'] ?? '',
                'labels'       => (object)($n['Spec']['Labels'] ?? []),
            ];
        }
        // Self zuerst, Rest alphabetisch
        usort($nodes, fn($a, $b) => [$b['self'], $a['hostname']] <=> [$a['self'], $b['hostname']]);
    }

    /* ---------- Services ---------- */
    $svcLs = jsonLines(dk("service ls --format '{{json .}}'", 'service ls'));
    if ($svcLs) {
        // join by name: `service ls` always shortens IDs to 12 characters, `inspect` returns them in full
        $lsByName = array_column($svcLs, null, 'Name');
        $svcArg = args(array_column($svcLs, 'ID'));
        $services = [];

        foreach (jsonDoc(dk("service inspect $svcArg", 'service inspect')) as $s) {
            $name  = $s['Spec']['Name'] ?? '?';
            $stack = $s['Spec']['Labels']['com.docker.stack.namespace'] ?? '';
            $ls    = $lsByName[$name] ?? [];
            $img   = $s['Spec']['TaskTemplate']['ContainerSpec']['Image'] ?? ($ls['Image'] ?? '');
            $prevImg = $s['PreviousSpec']['TaskTemplate']['ContainerSpec']['Image'] ?? '';
            $portList = array_map(fn($p) => [
                'target'    => $p['TargetPort'] ?? 0,
                'published' => $p['PublishedPort'] ?? 0,
                'proto'     => $p['Protocol'] ?? 'tcp',
            ], $s['Endpoint']['Ports'] ?? ($s['Spec']['EndpointSpec']['Ports'] ?? []));
            $services[$name] = [
                'id'          => $s['ID'] ?? '',
                'name'        => $name,
                'short'       => ($stack !== '' && str_starts_with($name, "{$stack}_")) ? substr($name, strlen($stack) + 1) : $name,
                'stack'       => $stack,
                'image'       => preg_replace('/@sha256:[0-9a-f]+$/i', '', $img),
                // no registry digest = locally built image (e.g. local/node-red-mcp) → only on nodes that have it
                'localImage'  => $img !== '' && strpos($img, '@') === false,
                'mode'        => isset($s['Spec']['Mode']['Global']) ? 'global' : 'replicated',
                'replicas'    => $ls['Replicas'] ?? '',
                'desired'     => isset($s['Spec']['Mode']['Replicated']) ? (int)($s['Spec']['Mode']['Replicated']['Replicas'] ?? 0) : null,
                'ports'       => implode(', ', array_map(fn($p) => $p['published'] ? "{$p['published']}→{$p['target']}/{$p['proto']}" : "{$p['target']}/{$p['proto']}", $portList)),
                'constraints' => $s['Spec']['TaskTemplate']['Placement']['Constraints'] ?? [],
                'platforms'   => array_values(array_unique(array_filter(array_map(
                    fn($p) => $p['Architecture'] ?? '',
                    $s['Spec']['TaskTemplate']['Placement']['Platforms'] ?? []
                )))),
                'labels'      => (object)($s['Spec']['TaskTemplate']['ContainerSpec']['Labels'] ?? []),
                'prefer'      => trim($s['Spec']['Labels']['swarm-view.prefer'] ?? ''),
                // display name in Unraid's list (replacement for container_name, which Swarm ignores)
                'alias'       => mb_substr(trim($s['Spec']['TaskTemplate']['ContainerSpec']['Labels']['swarm-view.name'] ?? ($s['Spec']['Labels']['swarm-view.name'] ?? '')), 0, 64),
                // offer rollback only if the image version changed (not for placement, labels …)
                'canRollback' => $prevImg !== '' && $prevImg !== $img,
                'rollbackTo'  => $prevImg !== '' && $prevImg !== $img ? sv_short_ref($prevImg) : '',
                'internal'    => !empty($s['Spec']['Labels']['swarm-view.agent']) || !empty($s['Spec']['Labels']['swarm-view.cleanup']),
                'mounts'      => array_map(fn($m) => [
                    'type'   => $m['Type'] ?? '',
                    'source' => $m['Source'] ?? '',
                    'target' => $m['Target'] ?? '',
                    'ro'     => !empty($m['ReadOnly']),
                ], $s['Spec']['TaskTemplate']['ContainerSpec']['Mounts'] ?? []),
                'portList'    => $portList,
                'tasks'       => [],
            ];
        }

        /* ---------- Tasks (what runs where) ---------- */
        $tasks = jsonLines(dk("service ps $svcArg --no-trunc --filter desired-state=running --format '{{json .}}'", 'service ps'));
        foreach ($tasks as $t) {
            $tn  = $t['Name'] ?? '';
            $dot = strrpos($tn, '.');
            $sn  = $dot === false ? $tn : substr($tn, 0, $dot);
            if (!isset($services[$sn])) continue;
            $node = $t['Node'] ?? '';
            $services[$sn]['tasks'][] = [
                'id'      => $t['ID'] ?? '',
                'slot'    => $dot === false ? '' : substr(substr($tn, $dot + 1), 0, 12),
                'node'    => $node,
                'local'   => $node !== '' && $node === $selfHost,
                'current' => $t['CurrentState'] ?? '',
                'error'   => $t['Error'] ?? '',
            ];
        }

        /* ---------- Details of tasks on other nodes: times, networks, container ID (one call) ---------- */
        $remoteIds = [];
        foreach ($services as $sv) foreach ($sv['tasks'] as $t) if ($t['id'] !== '' && !$t['local'] && $t['node'] !== '') $remoteIds[] = $t['id'];
        $detail = [];
        if ($remoteIds) {
            foreach (array_chunk($remoteIds, 60) as $chunk) {
                [, $o] = sv_run(array_merge([$DOCKER, 'inspect', '--type', 'task'], $chunk), null, [], 20);
                foreach ((json_decode(substr($o, (int)strpos($o, '[')), true) ?: []) as $ti) {
                    $nets = [];
                    foreach ($ti['NetworksAttachments'] ?? [] as $na) {
                        $nets[] = [
                            'name' => $na['Network']['Spec']['Name'] ?? '',
                            'addr' => preg_replace('#/\d+$#', '', $na['Addresses'][0] ?? ''),
                        ];
                    }
                    $detail[$ti['ID'] ?? ''] = [
                        'created'     => $ti['CreatedAt'] ?? '',
                        'since'       => $ti['Status']['Timestamp'] ?? '',
                        'containerId' => substr($ti['Status']['ContainerStatus']['ContainerID'] ?? '', 0, 12),
                        'networks'    => $nets,
                    ];
                }
            }
        }
        foreach ($services as &$sv) foreach ($sv['tasks'] as &$t) if (isset($detail[$t['id']])) $t += $detail[$t['id']];
        unset($sv, $t);

        // Node-Auswahl: aktuell per Constraint festgelegter Node ('' = Swarm entscheidet)
        $updates = sv_state_read('updates');
        $rebal = sv_state_read('monitor')['rebalance'] ?? [];
        foreach ($services as &$svc) {
            // a return to the preferred node is only shown while it really runs: the watchdog's temporary
            // node.hostname constraint is set, the tasks have not arrived yet and it is not overdue (watchdog: 10 min)
            $ph = $rebal[$svc['name']] ?? null;
            $cn = sv_constraint_node($svc['constraints']);
            $tmp = $ph && $cn !== '' && $cn === ($ph['node'] ?? '') && sv_now() - (int)($ph['since'] ?? 0) <= 660;
            $arrived = $tmp && $svc['tasks'] && !array_filter($svc['tasks'], fn($t) => !(stripos($t['current'], 'Running') === 0 && $t['node'] === $cn));
            $svc['placeNode'] = $tmp ? '' : $cn;      // the temporary binding is not a fixed node
            $svc['rebalancing'] = $tmp && !$arrived;
            $u = $updates['services'][$svc['name']] ?? null;
            $svc['update'] = $u ? (bool)$u['update'] : null;
            // replicas: from `service ls`; if missing, compute from the tasks
            if (!preg_match('/^\d+\/\d+/', (string)$svc['replicas'])) {
                $run = count(array_filter($svc['tasks'], fn($t) => stripos($t['current'], 'Running') === 0));
                $want = $svc['desired'] ?? count($svc['tasks']);
                $svc['replicas'] = "$run/$want";
            }
        }
        unset($svc);
        // do not show internal helper services (agent, cleanup job) as stacks
        $services = array_filter($services, fn($x) => !$x['internal']);

        foreach ($services as $svc) {
            $key = $svc['stack'] !== '' ? $svc['stack'] : '(no stack)';
            $stacks[$key][] = $svc;
        }
        uksort($stacks, fn($a, $b) => [$a === '(no stack)', $a] <=> [$b === '(no stack)', $b]);
        $stacks = array_map(
            fn($name, $svcs) => ['name' => $name, 'services' => array_values(
                (function ($l) { usort($l, fn($a, $b) => strcmp($a['short'], $b['short'])); return $l; })($svcs)
            )],
            array_keys($stacks), array_values($stacks)
        );
    }
}

/* ---------- CPU/RAM from the agent ---------- */
$cfgAll = sv_cfg();
$stats = ['enabled' => $cfgAll['STATS'] === 'yes', 'nodes' => (object)[], 'tasks' => (object)[]];
if ($stats['enabled'] && $swarm['active'] && $swarm['manager']) {
    $tasksStats = [];
    $nodeStats = [];
    foreach (sv_agent_read((int)$cfgAll['STATS_INTERVAL']) as $host => $d) {
        $mt = (int)($d['memTotal'] ?? 0);
        $nodeStats[$host] = [
            'cpu'   => round(($d['cpu'] ?? 0) / 10, 1),
            'mem'   => $mt ? round(100 * ($mt - (int)($d['memAvail'] ?? 0)) / $mt, 1) : null,
            'memGB' => round($mt / 1048576, 1),
            'stale' => !empty($d['stale']),
            'ncpu'  => (int)($d['ncpu'] ?? 0),
            'ts'    => (int)($d['ts'] ?? 0),
        ];
        if (!empty($d['stale'])) continue; // do not show container values of a stale state as current
        foreach ($d['stats'] ?? [] as $c) {
            // Containername eines Swarm-Tasks: <service>.<slot>.<task-id>
            $nm = $c['Name'] ?? '';
            $tid = substr($nm, (int)strrpos($nm, '.') + 1);
            if (strlen($tid) < 20) continue;
            $mem = $c['MemUsage'] ?? '';
            $approx = false;
            // node without memory cgroup: fallback value from the RSS sum of the container processes
            if (preg_match('#^0B\s*/\s*0B$#', trim($mem)) && isset($d['rss'][$nm])) {
                $mem = sprintf('%.2fMiB / %.2fGiB', $d['rss'][$nm] / 1024, (int)($d['memTotal'] ?? 0) / 1048576);
                $approx = true;
            }
            $tasksStats[$tid] = ['cpu' => $c['CPUPerc'] ?? '', 'mem' => $mem, 'memPerc' => $c['MemPerc'] ?? '', 'node' => $host,
                                 'ncpu' => (int)($d['ncpu'] ?? 0), 'memTotal' => (int)($d['memTotal'] ?? 0) * 1024, 'memApprox' => $approx];
        }
    }
    $stats['nodes'] = (object)$nodeStats;
    $stats['tasks'] = (object)$tasksStats;
}

/* ---------- Local containers (for the host column in Unraid's table) ---------- */
$containers = [];
foreach (jsonLines(dk("ps -a --format '{{json .}}'", 'docker ps')) as $c) {
    $labels = parseLabels($c['Labels'] ?? '');
    $containers[$c['Names'] ?? ''] = [
        'swarmStack'   => $labels['com.docker.stack.namespace'] ?? '',
        'swarmService' => $labels['com.docker.swarm.service.name'] ?? '',
        'compose'      => $labels['com.docker.compose.project'] ?? '',
    ];
}

echo json_encode([
    'ok'         => empty($errors),
    'host'       => $selfHost,
    'swarm'      => $swarm,
    'nodes'      => $nodes,
    'stacks'     => $stacks,
    'containers' => (object)$containers,
    'managed'    => sv_managed_stacks(),
    'stackInfo'  => (object)array_combine(sv_managed_stacks(), array_map(fn($n) => [
        'path'        => "$STACK_ROOT/$n",
        'description' => is_file("$STACK_ROOT/$n/description.txt") ? trim((string)file_get_contents("$STACK_ROOT/$n/description.txt")) : '',
    ], sv_managed_stacks())),
    'stats'      => $stats,
    'updates'    => ['enabled' => $cfgAll['UPDATE_CHECK'] === 'yes', 'checked' => sv_state_read('updates')['checked'] ?? null],
    'config'     => (function () { $c = sv_cfg(); return [
        'remoteRows' => $c['REMOTE_ROWS'],
        'hostColumn' => $c['HOST_COLUMN'] === 'yes',
        'folderLoad' => $c['FOLDER_LOAD'],
        'orphans'    => $c['ORPHAN_IMAGES'],
        'rebalance'  => $c['REBALANCE'] === 'yes',
        'rebalanceDelay' => (int)$c['REBALANCE_DELAY'],
        'refresh'    => (int)$c['REFRESH'],
    ]; })(),
    'errors'   => $errors,
    'time'     => date('H:i:s'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
