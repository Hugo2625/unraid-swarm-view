<?php
/*
 * Swarm View – Stack-Aktionen (nur POST; CSRF-Prüfung übernimmt Unraids local_prepend.php).
 * action = create | get | save | deploy | remove | restart | delete
 * Stack-Dateien: /boot/config/plugins/swarm-view/stacks/<name>/{docker-stack.yml,.env}
 */
require_once __DIR__ . '/common.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply(bool $ok, array $extra = []): void {
    echo json_encode(['ok' => $ok] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') reply(false, ['error' => 'Nur POST erlaubt']);

$action = $_POST['action'] ?? '';

/* ---------- Service-/Task-Aktionen (unabhängig vom Stack-Ordner) ---------- */
if ($action === 'logs') {
    $task = $_POST['task'] ?? '';
    if (!preg_match('/^[a-z0-9]{10,64}$/', $task)) reply(false, ['error' => 'Ungültige Task-ID']);
    $tail = max(50, min(2000, (int)($_POST['tail'] ?? 300)));
    [$rc, $out] = sv_run([$DOCKER, 'service', 'logs', '--no-task-ids', '--timestamps', '--tail', (string)$tail, $task], null, [], 30);
    reply($rc === 0, ['log' => $out !== '' ? $out : '(keine Ausgabe)']);
}
if ($action === 'svc-restart') {
    $svc = $_POST['service'] ?? '';
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $svc)) reply(false, ['error' => 'Ungültiger Service-Name']);
    [$rc, $out] = sv_run([$DOCKER, 'service', 'update', '--force', '--detach', $svc]);
    reply($rc === 0, ['log' => $rc === 0 ? "$svc wird neu ausgerollt." : $out]);
}

if ($action === 'place') {
    // Nur zur Laufzeit: der nächste "Stack Up" setzt wieder Constraints und Labels aus der docker-stack.yml
    //   mode=''       → Automatisch: keine Node-Bindung, kein Vorzug
    //   mode=fixed    → Fest: node.hostname==N (läuft nur dort, bei Ausfall gar nicht)
    //   mode=prefer   → Bevorzugt: Label swarm-view.prefer=N; bei Ausfall läuft er woanders, der Wächter holt ihn zurück
    $svc  = $_POST['service'] ?? '';
    $node = $_POST['node'] ?? '';
    $mode = $_POST['mode'] ?? ($node === '' ? '' : 'fixed');
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $svc)) reply(false, ['error' => 'Ungültiger Service-Name']);
    if (!in_array($mode, ['', 'fixed', 'prefer'], true)) reply(false, ['error' => 'Ungültiger Modus']);
    if ($mode === '') $node = '';
    elseif (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$/', $node)) reply(false, ['error' => 'Ungültiger Node-Name']);

    [$rc, $out] = sv_run([$DOCKER, 'service', 'inspect', $svc, '--format', '{{json .}}']);
    $svcObj = $rc === 0 ? json_decode($out, true) : null;
    $spec = $svcObj['Spec'] ?? null;
    if (!is_array($spec)) reply(false, ['error' => "Service \"$svc\" nicht gefunden", 'log' => $out]);
    if (isset($spec['Mode']['Global'])) reply(false, ['error' => 'Globale Services laufen auf allen Nodes']);

    // Wächter-Zustand nur unter seiner Sperre ändern
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
        $log[] = "$svc läuft jetzt fest auf $node.";
    } elseif ($mode === 'prefer') {
        array_push($args, '--label-add', "swarm-view.prefer=$node");
        // Schon auf N? Dann nichts verschieben. Sonst sofort hinlegen (wie die Rückkehr des Wächters: Constraint, danach wieder weg)
        [, $tout] = sv_run([$DOCKER, 'service', 'ps', $svc, '--filter', 'desired-state=running', '--format', '{{.Node}}|{{.CurrentState}}']);
        $rows = array_filter(explode("\n", trim($tout)));
        $onN = $rows && !array_filter($rows, fn($r) => !str_starts_with($r, "$node|Running"));
        [, $nout] = sv_run([$DOCKER, 'node', 'inspect', $node, '--format', '{{.Status.State}}|{{.Spec.Availability}}']);
        $ready = trim($nout) === 'ready|active';
        if (!$onN && $ready) {
            array_push($args, '--constraint-add', "node.hostname==$node");
            $state['rebalance'][$svc] = ['since' => sv_now(), 'node' => $node];
            $log[] = "$svc wird auf $node verlegt; bei einem Ausfall darf er woanders laufen.";
        } elseif (!$ready) {
            $log[] = "$node ist gerade nicht verfügbar – $svc bleibt, wo er ist, und wechselt zurück, sobald $node wieder stabil läuft.";
        } else {
            $log[] = "$svc läuft schon auf $node; bei einem Ausfall darf er woanders laufen.";
        }
        // Ohne Wächter keine Rückkehr → automatisch einschalten
        $cfg = sv_cfg();
        if ($cfg['REBALANCE'] !== 'yes') {
            $cfg['REBALANCE'] = 'yes';
            sv_cfg_write($cfg);
            sv_cron_apply($cfg);
            $log[] = 'Automatische Rückkehr in den Einstellungen eingeschaltet.';
        }
    } else {
        if ($hadPrefer) array_push($args, '--label-rm', 'swarm-view.prefer');
        $log[] = "$svc: Swarm entscheidet wieder selbst.";
    }
    if (count($args) === 4) {
        sv_state_write('monitor', $state);
        reply(true, ['log' => 'Keine Änderung nötig.']);
    }
    $args[] = $svc;
    [$rc, $out] = sv_run($args, null, [], 60);
    if ($rc !== 0) unset($state['rebalance'][$svc]);
    sv_state_write('monitor', $state);
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    reply($rc === 0, $rc === 0 ? ['log' => implode("\n", $log)] : ['error' => 'service update fehlgeschlagen', 'log' => $out]);
}

if ($action === 'cleanup') {
    $cfg = sv_cfg();
    [$ok, $log] = sv_cleanup($cfg['CLEANUP_IMAGES'] === 'yes', 40);
    reply($ok, ['log' => $log]);
}

if ($action === 'svc-update' || $action === 'svc-rollback') {
    $svc = $_POST['service'] ?? '';
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $svc)) reply(false, ['error' => 'Ungültiger Service-Name']);
    if ($action === 'svc-rollback') {
        [$rc, $out] = sv_run([$DOCKER, 'service', 'rollback', '--detach', $svc], null, [], 60);
        reply($rc === 0, $rc === 0 ? ['log' => "$svc wird auf die vorherige Version zurückgesetzt."] : ['error' => 'Rollback fehlgeschlagen', 'log' => $out]);
    }
    [$rc, $img] = sv_run([$DOCKER, 'service', 'inspect', $svc, '--format', '{{.Spec.TaskTemplate.ContainerSpec.Image}}']);
    if ($rc !== 0) reply(false, ['error' => "Service \"$svc\" nicht gefunden", 'log' => $img]);
    $ref = preg_replace('/@[^@\/]+$/', '', trim($img));
    // --image ohne Digest → Docker löst den aktuellen Digest der Registry auf
    [$rc, $out] = sv_run([$DOCKER, 'service', 'update', '--detach', '--with-registry-auth', '--image', $ref, $svc], null, [], 120);
    if ($rc === 0) {
        $u = sv_state_read('updates');
        if (isset($u['services'][$svc])) { $u['services'][$svc]['update'] = false; sv_state_write('updates', $u); }
    }
    reply($rc === 0, $rc === 0 ? ['log' => "$svc wird auf die neueste Version von $ref aktualisiert."] : ['error' => 'Update fehlgeschlagen', 'log' => $out]);
}

if ($action === 'update-check') {
    require_once __DIR__ . '/updates.php';   // oberste Ebene → Unraids Docker-Klassen global verfügbar
    $u = sv_check_updates();
    $avail = array_keys(array_filter($u['services'], fn($x) => $x['update']));
    $log = count($u['services']) . ' Services geprüft. ' . ($avail ? 'Updates verfügbar: ' . implode(', ', $avail) : 'Alles aktuell.');
    if ($u['errors']) $log .= "\n\nNicht prüfbar:\n" . implode("\n", array_unique($u['errors']));
    reply(true, ['log' => $log, 'checked' => $u['checked']]);
}

if ($action === 'notify-test') {
    $ok = sv_notify('Test-Benachrichtigung', 'Swarm View kann dich benachrichtigen.', $_POST['level'] ?? 'normal');
    reply($ok, $ok ? ['log' => 'Test-Benachrichtigung verschickt.'] : ['error' => 'notify-Skript fehlgeschlagen']);
}

if ($action === 'settings') {
    $cfg = sv_cfg();
    // Fehlende Felder behalten ihren bisherigen Wert
    $pick = fn($k, array $allowed) => isset($_POST[$k]) && in_array($_POST[$k], $allowed, true) ? $_POST[$k] : $cfg[$k];
    $in  = [
        'REMOTE_ROWS'      => $pick('REMOTE_ROWS', ['always', 'advanced', 'never']),
        'HOST_COLUMN'      => $pick('HOST_COLUMN', ['yes', 'no']),
        'FOLDER_LOAD'      => $pick('FOLDER_LOAD', ['total', 'local']),
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
            reply(false, ['error' => 'Ungültiger regulärer Ausdruck bei „Services ignorieren“']);
        }
        $in['NOTIFY_IGNORE'] = $re;
    }
    if (!sv_cfg_write($in)) reply(false, ['error' => 'Einstellungen konnten nicht gespeichert werden']);
    $log = ['Einstellungen gespeichert.', sv_cron_apply($in)];
    $sw0 = sv_swarm_settings();
    if ($sw0['active'] && $sw0['manager']) $log[] = sv_agent_apply($in);

    // Swarm-Cluster-Werte nur bei Änderung anfassen
    $sw = sv_swarm_settings();
    if ($sw['active'] && $sw['manager']) {
        $args = [$DOCKER, 'swarm', 'update'];
        $hist = isset($_POST['HISTORY_LIMIT']) ? max(1, min(20, (int)$_POST['HISTORY_LIMIT'])) : null;
        $hb   = isset($_POST['HEARTBEAT']) ? max(1, min(60, (int)$_POST['HEARTBEAT'])) : null;
        if ($hist !== null && $hist !== $sw['historyLimit']) array_push($args, '--task-history-limit', (string)$hist);
        if ($hb !== null && $hb !== $sw['heartbeat']) array_push($args, '--dispatcher-heartbeat', "{$hb}s");
        if (count($args) > 3) {
            [$rc, $out] = sv_run($args);
            if ($rc !== 0) reply(false, ['error' => 'swarm update fehlgeschlagen', 'log' => implode("\n", $log) . "\n\n" . $out]);
            if ($hist !== null && $hist !== $sw['historyLimit']) $log[] = "Task-Historie: $hist Container pro Service";
            if ($hb !== null && $hb !== $sw['heartbeat']) $log[] = "Heartbeat: {$hb} s";
        }
    }
    reply(true, ['log' => implode("\n", $log)]);
}

if ($action === 'node-availability') {
    $node  = $_POST['node'] ?? '';
    $avail = $_POST['availability'] ?? '';
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$/', $node)) reply(false, ['error' => 'Ungültiger Node']);
    if (!in_array($avail, ['active', 'pause', 'drain'], true)) reply(false, ['error' => 'Ungültige Verfügbarkeit']);
    [$rc, $out] = sv_run([$DOCKER, 'node', 'update', '--availability', $avail, $node]);
    $txt = ['active' => 'aktiv', 'pause' => 'pausiert (keine neuen Container)', 'drain' => 'in Wartung (Container werden abgezogen)'][$avail];
    reply($rc === 0, $rc === 0 ? ['log' => "$node ist jetzt $txt."] : ['error' => 'node update fehlgeschlagen', 'log' => $out]);
}

$name   = $_POST['name'] ?? '';
if (!sv_valid_name($name)) reply(false, ['error' => 'Ungültiger Stack-Name – erlaubt: a-z, 0-9, - und _ (max. 63 Zeichen)']);

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
        return [true, 'Hinweis: "docker stack config" nicht verfügbar – Datei nicht geprüft.'];
    }
    return [$rc === 0, $rc === 0 ? 'Datei ist gültig.' : $out];
}

$TEMPLATE = <<<YML
# Swarm-Stack "%s"
# Variablen aus der .env werden beim Deploy gesetzt (\${VAR}).
# Unterschiede zu Compose: kein build:, container_name/depends_on werden ignoriert,
# Neustart-Verhalten über deploy.restart_policy statt restart:.
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
      #     - node.hostname == raspberrypi   # nur auf dem Pi
      #     - node.role == manager           # nur auf Unraid

YML;

switch ($action) {
    case 'create':
        if (is_dir($dir)) reply(false, ['error' => "Stack \"$name\" existiert bereits"]);
        if (!@mkdir($dir, 0755, true)) reply(false, ['error' => "Ordner konnte nicht angelegt werden: $dir"]);
        file_put_contents($yml, sprintf($TEMPLATE, $name));
        file_put_contents($env, "# KEY=VALUE\n");
        reply(true, ['yml' => file_get_contents($yml), 'env' => file_get_contents($env)]);

    case 'get':
        if (!is_file($yml)) reply(false, ['error' => "Stack \"$name\" wird nicht von Swarm View verwaltet"]);
        reply(true, [
            'yml'      => file_get_contents($yml),
            'env'      => is_file($env) ? file_get_contents($env) : '',
            'path'     => $dir,
            'deployed' => deployed($name),
        ]);

    case 'describe':
        if (!is_dir($dir)) reply(false, ['error' => "Stack \"$name\" existiert nicht"]);
        $desc = trim(preg_replace('/\s+/u', ' ', (string)($_POST['description'] ?? '')));
        if (mb_strlen($desc) > 300) reply(false, ['error' => 'Beschreibung ist zu lang (max. 300 Zeichen)']);
        if ($desc === '') @unlink("$dir/description.txt");
        elseif (file_put_contents("$dir/description.txt", $desc . "\n") === false) reply(false, ['error' => 'Beschreibung konnte nicht gespeichert werden']);
        reply(true, ['description' => $desc]);

    case 'save':
        if (!is_dir($dir)) reply(false, ['error' => "Stack \"$name\" existiert nicht"]);
        $ymlNew = normalize($_POST['yml'] ?? '');
        if ($ymlNew === '') reply(false, ['error' => 'docker-stack.yml darf nicht leer sein']);
        file_put_contents($yml, $ymlNew);
        file_put_contents($env, normalize($_POST['env'] ?? ''));
        [$valid, $log] = validate();
        reply(true, ['valid' => $valid, 'log' => $log]);

    case 'deploy':
        if (!is_file($yml)) reply(false, ['error' => "Keine $STACK_FILE für \"$name\""]);
        [$valid, $vlog] = validate();
        if (!$valid) reply(false, ['error' => 'Stack-Datei ungültig', 'log' => $vlog]);

        // Stack Up: Images nur auflösen, wenn sie sich in der Datei geändert haben (kein Neustart unveränderter Services)
        // Update Stack: bei der Registry nach neuen Versionen schauen
        $update = ($_POST['mode'] ?? '') === 'update';
        $cmd = [$DOCKER, 'stack', 'deploy', '--detach=true', '--with-registry-auth', '--resolve-image', $update ? 'always' : 'changed'];
        if (sv_cfg()['DEPLOY_PRUNE'] === 'yes') $cmd[] = '--prune';
        array_push($cmd, '-c', $STACK_FILE, $name);
        [$rc, $out] = sv_run($cmd, $dir, sv_parse_env($env), 300);
        reply($rc === 0, ['log' => ($update ? "Update: neueste Image-Versionen werden geholt.\n" : '') . ($out ?: 'Deploy angestoßen.')]);

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
            $log[] = "$svc: " . ($r === 0 ? 'neu gestartet' : $o);
        }
        reply($ok, ['log' => implode("\n", $log) ?: 'Keine Services im Stack.']);

    case 'delete':
        if (deployed($name)) reply(false, ['error' => 'Stack ist noch deployt – erst "Stack Down"']);
        if (!is_dir($dir)) reply(false, ['error' => "Stack \"$name\" existiert nicht"]);
        [$rc, $out] = sv_run(['rm', '-rf', '--', $dir]);
        reply($rc === 0, ['log' => $rc === 0 ? "Dateien von \"$name\" gelöscht." : $out]);

    default:
        reply(false, ['error' => 'Unbekannte Aktion']);
}
