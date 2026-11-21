<?php
/*
 * Swarm View – actions (POST only; Unraid's local_prepend.php checks the CSRF token).
 * action = create | get | save | deploy | remove | restart | delete
 * Stack files: /boot/config/plugins/swarm-view/stacks/<name>/{docker-stack.yml,.env}
 */
require_once __DIR__ . '/common.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply(bool $ok, array $extra = []): void {
    echo json_encode(['ok' => $ok] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') reply(false, ['error' => sv_t('Only POST allowed')]);

$action = $_POST['action'] ?? '';

/* ---------- Service/task actions (independent of the stack folder) ---------- */
if ($action === 'logs') {
    $task = $_POST['task'] ?? '';
    if (!preg_match('/^[a-z0-9]{10,64}$/', $task)) reply(false, ['error' => sv_t('Invalid task ID')]);
    $tail = max(50, min(2000, (int)($_POST['tail'] ?? 300)));
    [$rc, $out] = sv_run([$DOCKER, 'service', 'logs', '--no-task-ids', '--timestamps', '--tail', (string)$tail, $task], null, [], 30);
    reply($rc === 0, ['log' => $out !== '' ? $out : sv_t('(no output)')]);
}
if ($action === 'svc-restart') {
    $svc = $_POST['service'] ?? '';
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $svc)) reply(false, ['error' => sv_t('Invalid service name')]);
    [$rc, $out] = sv_run([$DOCKER, 'service', 'update', '--force', '--detach', $svc]);
    reply($rc === 0, ['log' => $rc === 0 ? sv_t('{0} is being rolled out again.', $svc) : $out]);
}

if ($action === 'place') {
    // Runtime only: the next "Stack Up" sets constraints and labels from docker-stack.yml again
    //   mode=''       → automatic: no node binding, no preference
    //   mode=fixed    → fixed: node.hostname==N (runs only there, not at all if it is down)
    //   mode=prefer   → preferred: label swarm-view.prefer=N; runs elsewhere while N is down, the watchdog brings it back
    $svc  = $_POST['service'] ?? '';
    $node = $_POST['node'] ?? '';
    $mode = $_POST['mode'] ?? ($node === '' ? '' : 'fixed');
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $svc)) reply(false, ['error' => sv_t('Invalid service name')]);
    if (!in_array($mode, ['', 'fixed', 'prefer'], true)) reply(false, ['error' => sv_t('Invalid mode')]);
    if ($mode === '') $node = '';
    elseif (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$/', $node)) reply(false, ['error' => sv_t('Invalid node name')]);

    [$rc, $out] = sv_run([$DOCKER, 'service', 'inspect', $svc, '--format', '{{json .}}']);
    $svcObj = $rc === 0 ? json_decode($out, true) : null;
    $spec = $svcObj['Spec'] ?? null;
    if (!is_array($spec)) reply(false, ['error' => sv_t('Service "{0}" not found', $svc), 'log' => $out]);
    if (isset($spec['Mode']['Global'])) reply(false, ['error' => sv_t('Global services run on all nodes')]);

    // change the watchdog state only while holding its lock
    @mkdir($STATE_DIR, 0755, true);
    $lock = fopen("$STATE_DIR/monitor.lock", 'c');
    if ($lock) flock($lock, LOCK_EX);
    $state = sv_state_read('monitor');
    unset($state['rebalance'][$svc]);

    $cons = $spec['TaskTemplate']['Placement']['Constraints'] ?? [];
    $hadPrefer = trim($spec['Labels']['swarm-view.prefer'] ?? '') !== '';
    $args = [$DOCKER, 'service', 'update', '--detach'];
    foreach ($cons as $c) {
        if (sv_is_node_constraint($c)) array_push($args, '--constraint-rm', $c);
    }
    $log = [];
    if ($mode === 'fixed') {
        array_push($args, '--constraint-add', "node.hostname==$node");
        if ($hadPrefer) array_push($args, '--label-rm', 'swarm-view.prefer');
        $log[] = sv_t('{0} now runs only on {1}.', $svc, $node);
    } elseif ($mode === 'prefer') {
        array_push($args, '--label-add', "swarm-view.prefer=$node");
        // already on N? then do not move. Otherwise move now (like the watchdog's return: constraint, removed again afterwards)
        [, $tout] = sv_run([$DOCKER, 'service', 'ps', $svc, '--filter', 'desired-state=running', '--format', '{{.Node}}|{{.CurrentState}}']);
        $rows = array_filter(explode("\n", trim($tout)));
        $onN = $rows && !array_filter($rows, fn($r) => !str_starts_with($r, "$node|Running"));
        [, $nout] = sv_run([$DOCKER, 'node', 'inspect', $node, '--format', '{{.Status.State}}|{{.Spec.Availability}}']);
        $ready = trim($nout) === 'ready|active';
        if (!$onN && $ready) {
            array_push($args, '--constraint-add', "node.hostname==$node");
            $state['rebalance'][$svc] = ['since' => sv_now(), 'node' => $node];
            $log[] = sv_t('{0} is moved to {1}; if the node goes down it may run elsewhere.', $svc, $node);
        } elseif (!$ready) {
            $log[] = sv_t('{0} is not available right now – {1} stays where it is and moves back as soon as {0} runs stable again.', $node, $svc);
        } else {
            $log[] = sv_t('{0} already runs on {1}; if the node goes down it may run elsewhere.', $svc, $node);
        }
        // no return without the watchdog → switch it on automatically
        $cfg = sv_cfg();
        if ($cfg['REBALANCE'] !== 'yes') {
            $cfg['REBALANCE'] = 'yes';
            sv_cfg_write($cfg);
            sv_cron_apply($cfg);
            $log[] = sv_t('Automatic return switched on in the settings.');
        }
    } else {
        if ($hadPrefer) array_push($args, '--label-rm', 'swarm-view.prefer');
        $log[] = sv_t('{0}: Swarm decides again.', $svc);
    }
    if (count($args) === 4) {
        sv_state_write('monitor', $state);
        reply(true, ['log' => sv_t('No change needed.')]);
    }
    $args[] = $svc;
    [$rc, $out] = sv_run($args, null, [], 60);
    if ($rc !== 0) unset($state['rebalance'][$svc]);
    sv_state_write('monitor', $state);
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    reply($rc === 0, $rc === 0 ? ['log' => implode("\n", $log)] : ['error' => sv_t('{0} failed', 'service update'), 'log' => $out]);
}

if ($action === 'cleanup') {
    $cfg = sv_cfg();
    [$ok, $log] = sv_cleanup($cfg['CLEANUP_IMAGES'] === 'yes', 40);
    reply($ok, ['log' => $log]);
}

if ($action === 'svc-update' || $action === 'svc-rollback') {
    $svc = $_POST['service'] ?? '';
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $svc)) reply(false, ['error' => sv_t('Invalid service name')]);
    if ($action === 'svc-rollback') {
        [$rc, $out] = sv_run([$DOCKER, 'service', 'rollback', '--detach', $svc], null, [], 60);
        reply($rc === 0, $rc === 0 ? ['log' => sv_t('{0} is rolled back to the previous version.', $svc)] : ['error' => sv_t('{0} failed', 'Rollback'), 'log' => $out]);
    }
    [$rc, $img] = sv_run([$DOCKER, 'service', 'inspect', $svc, '--format', '{{.Spec.TaskTemplate.ContainerSpec.Image}}']);
    if ($rc !== 0) reply(false, ['error' => sv_t('Service "{0}" not found', $svc), 'log' => $img]);
    $ref = preg_replace('/@[^@\/]+$/', '', trim($img));
    // --image without digest → Docker resolves the current digest from the registry
    [$rc, $out] = sv_run([$DOCKER, 'service', 'update', '--detach', '--with-registry-auth', '--image', $ref, $svc], null, [], 120);
    if ($rc === 0) {
        $u = sv_state_read('updates');
        if (isset($u['services'][$svc])) { $u['services'][$svc]['update'] = false; sv_state_write('updates', $u); }
    }
    reply($rc === 0, $rc === 0 ? ['log' => sv_t('{0} is updated to the newest version of {1}.', $svc, $ref)] : ['error' => sv_t('{0} failed', 'Update'), 'log' => $out]);
}

if ($action === 'update-check') {
    require_once __DIR__ . '/updates.php';   // top level → Unraid's Docker classes available globally
    $u = sv_check_updates();
    $avail = array_keys(array_filter($u['services'], fn($x) => $x['update']));
    $log = sv_t('{0} services checked.', count($u['services'])) . ' ' . ($avail ? sv_t('Updates available: {0}', implode(', ', $avail)) : sv_t('Everything is up to date.'));
    if ($u['errors']) $log .= "\n\n" . sv_t('Could not be checked:') . "\n" . implode("\n", array_unique($u['errors']));
    reply(true, ['log' => $log, 'checked' => $u['checked']]);
}

if ($action === 'notify-test') {
    $ok = sv_notify(sv_t('Test notification'), sv_t('Swarm View can notify you.'), $_POST['level'] ?? 'normal');
    reply($ok, $ok ? ['log' => sv_t('Test notification sent.')] : ['error' => sv_t('{0} failed', 'notify')]);
}

if ($action === 'settings') {
    $cfg = sv_cfg();
    // missing fields keep their previous value
    $pick = fn($k, array $allowed) => isset($_POST[$k]) && in_array($_POST[$k], $allowed, true) ? $_POST[$k] : $cfg[$k];
    $in  = [
        'REMOTE_ROWS'      => $pick('REMOTE_ROWS', ['always', 'advanced', 'never']),
        'HOST_COLUMN'      => $pick('HOST_COLUMN', ['yes', 'no']),
        'FOLDER_LOAD'      => $pick('FOLDER_LOAD', ['total', 'local']),
        'LANGUAGE'         => $pick('LANGUAGE', ['auto', 'en', 'de']),
        'ORPHAN_IMAGES'    => $pick('ORPHAN_IMAGES', ['show', 'swarm', 'hide']),
        'SWARM_POSITION'   => $pick('SWARM_POSITION', ['top', 'middle', 'bottom']),
        'REFRESH'          => isset($_POST['REFRESH']) ? (string)max(5, min(300, (int)$_POST['REFRESH'])) : $cfg['REFRESH'],
        'DEPLOY_PRUNE'     => $pick('DEPLOY_PRUNE', ['yes', 'no']),
        'CLEANUP_SCHEDULE' => $pick('CLEANUP_SCHEDULE', ['off', 'daily', 'weekly']),
        'CLEANUP_IMAGES'   => $pick('CLEANUP_IMAGES', ['yes', 'no']),
    ];
    $yn = ['yes', 'no'];
    $lvl = ['normal', 'warning', 'alert'];
    $num = fn($k, $min, $max) => isset($_POST[$k]) && is_numeric($_POST[$k]) ? (string)max($min, min($max, (int)$_POST[$k])) : $cfg[$k];
    $in += [
        'UPDATE_CHECK'         => $pick('UPDATE_CHECK', $yn),
        'UPDATE_INTERVAL'      => $num('UPDATE_INTERVAL', 1, 168),
        'NOTIFY_ENABLED'       => $pick('NOTIFY_ENABLED', $yn),
        'NOTIFY_NODE'          => $pick('NOTIFY_NODE', $yn),
        'NOTIFY_NODE_DELAY'    => $num('NOTIFY_NODE_DELAY', 0, 120),
        'NOTIFY_NODE_LEVEL'    => $pick('NOTIFY_NODE_LEVEL', $lvl),
        'NOTIFY_CRASH'         => $pick('NOTIFY_CRASH', $yn),
        'NOTIFY_CRASH_COUNT'   => $num('NOTIFY_CRASH_COUNT', 2, 50),
        'NOTIFY_CRASH_WINDOW'  => $num('NOTIFY_CRASH_WINDOW', 2, 240),
        'NOTIFY_CRASH_LEVEL'   => $pick('NOTIFY_CRASH_LEVEL', $lvl),
        'NOTIFY_PENDING'       => $pick('NOTIFY_PENDING', $yn),
        'NOTIFY_PENDING_DELAY' => $num('NOTIFY_PENDING_DELAY', 0, 240),
        'NOTIFY_PENDING_LEVEL' => $pick('NOTIFY_PENDING_LEVEL', $lvl),
        'NOTIFY_MOVED'         => $pick('NOTIFY_MOVED', $yn),
        'NOTIFY_MOVED_LEVEL'   => $pick('NOTIFY_MOVED_LEVEL', $lvl),
        'NOTIFY_UPDATES'       => $pick('NOTIFY_UPDATES', $yn),
        'NOTIFY_UPDATES_LEVEL' => $pick('NOTIFY_UPDATES_LEVEL', $lvl),
        'NOTIFY_RECOVERY'      => $pick('NOTIFY_RECOVERY', $yn),
        'NOTIFY_REPEAT'        => $num('NOTIFY_REPEAT', 0, 168),
        'NOTIFY_IGNORE'        => $cfg['NOTIFY_IGNORE'],
        'REBALANCE'            => $pick('REBALANCE', $yn),
        'REBALANCE_DELAY'      => $num('REBALANCE_DELAY', 1, 120),
        'STATS'                => $pick('STATS', $yn),
        'STATS_INTERVAL'       => $num('STATS_INTERVAL', 5, 300),
    ];
    if (isset($_POST['NOTIFY_IGNORE'])) {
        $re = str_replace('"', '', trim((string)$_POST['NOTIFY_IGNORE']));
        if ($re !== '' && @preg_match('~' . str_replace('~', '\~', $re) . '~', '') === false) {
            reply(false, ['error' => sv_t('Invalid regular expression in "Ignore services"')]);
        }
        $in['NOTIFY_IGNORE'] = $re;
    }
    if (!sv_cfg_write($in)) reply(false, ['error' => sv_t('Settings could not be saved')]);
    $log = [sv_t('Settings saved.'), sv_cron_apply($in)];
    // Worth a popup: watchdog runs, agent (re)started or stopped, swarm values changed
    $notable = sv_monitor_needed($in) && !sv_monitor_needed($cfg);
    $sw0 = sv_swarm_settings();
    if ($sw0['active'] && $sw0['manager']) { $log[] = sv_agent_apply($in, $agentChanged); $notable = $notable || $agentChanged; }

    // only touch swarm cluster values when they changed
    $sw = sv_swarm_settings();
    if ($sw['active'] && $sw['manager']) {
        $args = [$DOCKER, 'swarm', 'update'];
        $hist = isset($_POST['HISTORY_LIMIT']) ? max(1, min(20, (int)$_POST['HISTORY_LIMIT'])) : null;
        $hb   = isset($_POST['HEARTBEAT']) ? max(1, min(60, (int)$_POST['HEARTBEAT'])) : null;
        if ($hist !== null && $hist !== $sw['historyLimit']) array_push($args, '--task-history-limit', (string)$hist);
        if ($hb !== null && $hb !== $sw['heartbeat']) array_push($args, '--dispatcher-heartbeat', "{$hb}s");
        if (count($args) > 3) {
            [$rc, $out] = sv_run($args);
            if ($rc !== 0) reply(false, ['error' => sv_t('{0} failed', 'swarm update'), 'log' => implode("\n", $log) . "\n\n" . $out]);
            if ($hist !== null && $hist !== $sw['historyLimit']) $log[] = sv_t('Task history: {0} containers per service', $hist);
            if ($hb !== null && $hb !== $sw['heartbeat']) $log[] = "Heartbeat: {$hb} s";
            $notable = true;
        }
    }
    reply(true, ['log' => implode("\n", $log), 'notable' => $notable, 'reload' => ($in['LANGUAGE'] ?? '') !== ($cfg['LANGUAGE'] ?? '')]);
}

if ($action === 'node-availability') {
    $node  = $_POST['node'] ?? '';
    $avail = $_POST['availability'] ?? '';
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$/', $node)) reply(false, ['error' => sv_t('Invalid node')]);
    if (!in_array($avail, ['active', 'pause', 'drain'], true)) reply(false, ['error' => sv_t('Invalid availability')]);
    [$rc, $out] = sv_run([$DOCKER, 'node', 'update', '--availability', $avail, $node]);
    $txt = ['active' => sv_t('active'), 'pause' => sv_t('paused (no new containers)'), 'drain' => sv_t('in maintenance (containers are moved away)')][$avail];
    reply($rc === 0, $rc === 0 ? ['log' => sv_t('{0} is now {1}.', $node, $txt)] : ['error' => sv_t('{0} failed', 'node update'), 'log' => $out]);
}

$name   = $_POST['name'] ?? '';
if (!sv_valid_name($name)) reply(false, ['error' => sv_t('Invalid stack name – allowed: a-z, 0-9, - and _ (max. 63 characters)')]);

$dir = "$STACK_ROOT/$name";
$yml = "$dir/$STACK_FILE";
$env = "$dir/.env";

function normalize(string $s): string {
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    return rtrim($s) === '' ? '' : rtrim($s) . "\n";
}

function deployed(string $name): bool {
    global $DOCKER;
    [$rc, $out] = sv_run([$DOCKER, 'stack', 'ls', '--format', '{{.Name}}']);
    return $rc === 0 && in_array($name, explode("\n", $out), true);
}

function validate(): array {
    global $DOCKER, $yml, $env, $dir, $STACK_FILE;
    [$rc, $out] = sv_run([$DOCKER, 'stack', 'config', '-c', $STACK_FILE], $dir, sv_parse_env($env), 30);
    if ($rc !== 0 && preg_match('/unknown command|is not a docker command/i', $out)) {
        return [true, sv_t('Note: "docker stack config" not available – file not checked.')];
    }
    return [$rc === 0, $rc === 0 ? sv_t('File is valid.') : $out];
}

$TEMPLATE = <<<YML
# Swarm stack "%s"
# Variables from .env are set on deploy (\${VAR}).
# Differences to Compose: no build:, container_name/depends_on are ignored,
# restart behaviour via deploy.restart_policy instead of restart:.
# Display name in Unraid's list: label swarm-view.name
version: "3.8"

services:
  app:
    image: nginx:alpine
    ports:
      - "8080:80"
    deploy:
      replicas: 1
      restart_policy:
        condition: on-failure
      # placement:
      #   constraints:
      #     - node.hostname == raspberrypi   # only on the Pi
      #     - node.role == manager           # only on the manager (Unraid)

YML;

switch ($action) {
    case 'create':
        if (is_dir($dir)) reply(false, ['error' => sv_t('Stack "{0}" already exists', $name)]);
        if (!@mkdir($dir, 0755, true)) reply(false, ['error' => sv_t('Folder could not be created: {0}', $dir)]);
        file_put_contents($yml, sprintf($TEMPLATE, $name));
        file_put_contents($env, "# KEY=VALUE\n");
        reply(true, ['yml' => file_get_contents($yml), 'env' => file_get_contents($env)]);

    case 'get':
        if (!is_file($yml)) reply(false, ['error' => sv_t('Stack "{0}" is not managed by Swarm View', $name)]);
        reply(true, [
            'yml'      => file_get_contents($yml),
            'env'      => is_file($env) ? file_get_contents($env) : '',
            'path'     => $dir,
            'deployed' => deployed($name),
        ]);

    case 'describe':
        if (!is_dir($dir)) reply(false, ['error' => sv_t('Stack "{0}" does not exist', $name)]);
        $desc = trim(preg_replace('/\s+/u', ' ', (string)($_POST['description'] ?? '')));
        if (mb_strlen($desc) > 300) reply(false, ['error' => sv_t('Description is too long (max. 300 characters)')]);
        if ($desc === '') @unlink("$dir/description.txt");
        elseif (file_put_contents("$dir/description.txt", $desc . "\n") === false) reply(false, ['error' => sv_t('Description could not be saved')]);
        reply(true, ['description' => $desc]);

    case 'save':
        if (!is_dir($dir)) reply(false, ['error' => sv_t('Stack "{0}" does not exist', $name)]);
        $ymlNew = normalize($_POST['yml'] ?? '');
        if ($ymlNew === '') reply(false, ['error' => sv_t('docker-stack.yml must not be empty')]);
        file_put_contents($yml, $ymlNew);
        file_put_contents($env, normalize($_POST['env'] ?? ''));
        [$valid, $log] = validate();
        reply(true, ['valid' => $valid, 'log' => $log]);

    case 'deploy':
        if (!is_file($yml)) reply(false, ['error' => sv_t('No {0} for "{1}"', $STACK_FILE, $name)]);
        [$valid, $vlog] = validate();
        if (!$valid) reply(false, ['error' => sv_t('Stack file invalid'), 'log' => $vlog]);

        // Stack Up: resolve images only if they changed in the file (no restart of unchanged services)
        // Update Stack: ask the registry for new versions
        $update = ($_POST['mode'] ?? '') === 'update';
        $cmd = [$DOCKER, 'stack', 'deploy', '--detach=true', '--with-registry-auth', '--resolve-image', $update ? 'always' : 'changed'];
        if (sv_cfg()['DEPLOY_PRUNE'] === 'yes') $cmd[] = '--prune';
        array_push($cmd, '-c', $STACK_FILE, $name);
        [$rc, $out] = sv_run($cmd, $dir, sv_parse_env($env), 300);
        reply($rc === 0, ['log' => ($update ? sv_t('Update: pulling the newest image versions.') . "\n" : '') . ($out ?: sv_t('Deploy started.'))]);

    case 'remove':
        [$rc, $out] = sv_run([$DOCKER, 'stack', 'rm', $name]);
        reply($rc === 0, ['log' => $out]);

    case 'restart':
        [$rc, $out] = sv_run([$DOCKER, 'stack', 'services', $name, '--format', '{{.Name}}']);
        if ($rc !== 0) reply(false, ['log' => $out]);
        $log = [];
        $ok = true;
        foreach (array_filter(explode("\n", $out)) as $svc) {
            [$r, $o] = sv_run([$DOCKER, 'service', 'update', '--force', '--detach', $svc]);
            $ok = $ok && $r === 0;
            $log[] = "$svc: " . ($r === 0 ? sv_t('restarted') : $o);
        }
        reply($ok, ['log' => implode("\n", $log) ?: sv_t('No services in the stack.')]);

    case 'delete':
        if (deployed($name)) reply(false, ['error' => sv_t('Stack is still deployed – "Stack Down" first')]);
        if (!is_dir($dir)) reply(false, ['error' => sv_t('Stack "{0}" does not exist', $name)]);
        [$rc, $out] = sv_run(['rm', '-rf', '--', $dir]);
        reply($rc === 0, ['log' => $rc === 0 ? sv_t('Files of "{0}" deleted.', $name) : $out]);

    default:
        reply(false, ['error' => sv_t('Unknown action')]);
}
