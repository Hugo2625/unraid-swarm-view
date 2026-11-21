<?php
/* Swarm View – gemeinsame Helfer für api.php und actions.php */

// Über $GLOBALS, weil Unraid .page-Dateien innerhalb einer Funktion rendert
$GLOBALS['DOCKER']     = getenv('SV_DOCKER') ?: '/usr/bin/docker';
$GLOBALS['STACK_ROOT'] = getenv('SV_STACK_ROOT') ?: '/boot/config/plugins/swarm-view/stacks';
$GLOBALS['STACK_FILE'] = 'docker-stack.yml';
$GLOBALS['CFG_FILE']   = getenv('SV_CFG') ?: '/boot/config/plugins/swarm-view/swarm-view.cfg';
$GLOBALS['CRON_FILE']  = dirname($GLOBALS['CFG_FILE']) . '/cleanup.cron';
$GLOBALS['STATE_DIR']  = getenv('SV_STATE') ?: '/tmp/swarm-view';
$GLOBALS['NOTIFY']     = getenv('SV_NOTIFY') ?: '/usr/local/emhttp/plugins/dynamix/scripts/notify';
const SV_AGENT = 'swarm-view-agent';

/** Aktuelle Zeit (in Tests per SV_NOW überschreibbar) */
function sv_now(): int {
    return (int)(getenv('SV_NOW') ?: time());
}

/** Plugin-Einstellungen mit Standardwerten */
const SV_DEFAULTS = [
    'REMOTE_ROWS'      => 'always',  // always | advanced | never
    'HOST_COLUMN'      => 'yes',
    'SWARM_POSITION'   => 'bottom', // top | middle | bottom – Lage der Swarm-Leiste auf der Docker-Seite
    'ORPHAN_IMAGES'    => 'swarm', // show | swarm | hide – verwaiste Images in Unraids Liste
    'FOLDER_LOAD'      => 'total', // total | local – CPU/RAM der Folder-View-Ordner
    'REFRESH'          => '15',      // Sekunden
    'DEPLOY_PRUNE'     => 'yes',
    'CLEANUP_SCHEDULE' => 'off',     // off | daily | weekly
    'CLEANUP_IMAGES'   => 'yes',

    // Update-Hinweise
    'UPDATE_CHECK'     => 'no',
    'UPDATE_INTERVAL'  => '12',      // Stunden

    // Benachrichtigungen
    'NOTIFY_ENABLED'       => 'no',
    'NOTIFY_NODE'          => 'yes',
    'NOTIFY_NODE_DELAY'    => '2',       // Minuten offline, bevor gemeldet wird
    'NOTIFY_NODE_LEVEL'    => 'alert',
    'NOTIFY_CRASH'         => 'yes',
    'NOTIFY_CRASH_COUNT'   => '3',       // Fehlstarts …
    'NOTIFY_CRASH_WINDOW'  => '10',      // … innerhalb von Minuten
    'NOTIFY_CRASH_LEVEL'   => 'warning',
    'NOTIFY_PENDING'       => 'yes',
    'NOTIFY_PENDING_DELAY' => '5',       // Minuten nicht platzierbar
    'NOTIFY_PENDING_LEVEL' => 'warning',
    'NOTIFY_MOVED'         => 'no',      // Service ist auf anderen Node ausgewichen / zurückgekehrt
    'NOTIFY_MOVED_LEVEL'   => 'normal',
    'NOTIFY_UPDATES'       => 'yes',
    'NOTIFY_UPDATES_LEVEL' => 'normal',
    'NOTIFY_RECOVERY'      => 'yes',     // Entwarnung, wenn ein Problem behoben ist
    'NOTIFY_REPEAT'        => '0',       // Stunden bis zur Erinnerung (0 = nie)
    'NOTIFY_IGNORE'        => '',        // Regex für Service-Namen, die ignoriert werden

    // Bevorzugter Node
    'REBALANCE'        => 'no',
    'REBALANCE_DELAY'  => '5',       // Minuten, die der bevorzugte Node stabil sein muss

    // CPU/RAM-Agent
    'STATS'            => 'no',
    'STATS_INTERVAL'   => '10',      // Sekunden
];

function sv_cfg(): array {
    global $CFG_FILE;
    $c = is_file($CFG_FILE) ? (@parse_ini_file($CFG_FILE, false, INI_SCANNER_RAW) ?: []) : [];
    return array_merge(SV_DEFAULTS, array_intersect_key($c, SV_DEFAULTS));
}

function sv_cfg_write(array $cfg): bool {
    global $CFG_FILE;
    @mkdir(dirname($CFG_FILE), 0755, true);
    $out = '';
    foreach (array_merge(SV_DEFAULTS, array_intersect_key($cfg, SV_DEFAULTS)) as $k => $v) {
        $out .= $k . '="' . str_replace('"', '', (string)$v) . "\"\n";
    }
    $ok = file_put_contents($CFG_FILE, $out) !== false;
    sv_menu_write($cfg['SWARM_POSITION'] ?? SV_DEFAULTS['SWARM_POSITION']);
    return $ok;
}

/**
 * Lage der Swarm-Leiste auf der Docker-Seite. SwarmView.page liest ihren Menu-Rang per
 *   Menu="/boot/config/plugins/swarm-view/menu.cfg MENU=Docker:3"
 * aus dieser Datei (Unraids PageBuilder: get_file_key). Sortiert wird natürlich nach "<Rang><Seitenname>":
 *   Docker:0 → vor "1DockerContainers" · Docker:1 → nach "1DockerContainers" ("S" > "D"), vor Compose (Rang 2) · Docker:3 → ans Ende
 */
function sv_menu_write(string $pos): void {
    global $CFG_FILE;
    $rank = ['top' => '0', 'middle' => '1', 'bottom' => '3'][$pos] ?? '3';
    @file_put_contents(dirname($CFG_FILE) . '/menu.cfg', "MENU=\"Docker:$rank\"\n");
}

/** Gültiger Stack-Name? (wird auch als Ordnername und Service-Präfix benutzt) */
function sv_valid_name(string $n): bool {
    return (bool)preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $n);
}

/** Namen aller von Swarm View verwalteten Stacks (Ordner mit docker-stack.yml) */
function sv_managed_stacks(): array {
    global $STACK_ROOT, $STACK_FILE;
    $out = [];
    foreach (glob("$STACK_ROOT/*/$STACK_FILE") ?: [] as $f) {
        $n = basename(dirname($f));
        if (sv_valid_name($n)) $out[] = $n;
    }
    sort($out);
    return $out;
}

/** .env (KEY=VALUE) parsen – Kommentare, "export ", Anführungszeichen werden behandelt */
function sv_parse_env(string $file): array {
    $env = [];
    if (!is_file($file)) return $env;
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (str_starts_with($line, 'export ')) $line = trim(substr($line, 7));
        $p = strpos($line, '=');
        if ($p === false) continue;
        $k = trim(substr($line, 0, $p));
        $v = trim(substr($line, $p + 1));
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) $v = substr($v, 1, -1);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $k)) $env[$k] = $v;
    }
    return $env;
}

/**
 * Befehl ohne Shell ausführen (Array-Form → kein Quoting-Problem).
 * @return array [returncode, kombinierte Ausgabe]
 */
function sv_run(array $cmd, ?string $cwd = null, array $extraEnv = [], int $timeout = 120): array {
    $env = array_merge(getenv(), $extraEnv);
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd, $env);
    if (!is_resource($p)) return [127, 'Konnte Befehl nicht starten: ' . implode(' ', $cmd)];
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    $out = '';
    $start = time();
    while (true) {
        $chunk = fread($pipes[1], 8192);
        if ($chunk !== false && $chunk !== '') { $out .= $chunk; continue; }
        $st = proc_get_status($p);
        if (!$st['running']) { $out .= stream_get_contents($pipes[1]); break; }
        if (time() - $start > $timeout) { proc_terminate($p); $out .= "\n[Timeout nach {$timeout}s]"; break; }
        usleep(50000);
    }
    fclose($pipes[1]);
    $rc = proc_close($p);
    if (isset($st) && !$st['running'] && $st['exitcode'] !== -1) $rc = $st['exitcode'];
    return [$rc, trim($out)];
}

/* ---------- Node-Auswahl (nur Laufzeit, bis zum nächsten Stack Up) ---------- */

/** Constraints, die einen Service an einen Node binden (hostname/role/id) */
function sv_is_node_constraint(string $c): bool {
    return (bool)preg_match('/^\s*node\.(hostname|role|id)\s*(==|!=)/', $c);
}

/** Node aus einer hostname-Constraint ("node.hostname == X" in beliebiger Schreibweise) */
function sv_constraint_node(array $cons): string {
    foreach ($cons as $c) {
        if (preg_match('/^\s*node\.hostname\s*==\s*(\S+)\s*$/', $c, $m)) return $m[1];
    }
    return '';
}

/* ---------- Aufräumen (Button, Einstellungen und Cron) ---------- */

/**
 * Startet auf jedem Node einen Einmal-Job, der gestoppte Swarm-Container
 * (und optional verwaiste Images) löscht. Wartet max. $wait Sekunden.
 * @return array [ok, log]
 */
function sv_cleanup(bool $images, int $wait = 40): array {
    global $DOCKER;
    $job = 'swarm-view-cleanup';
    sv_run([$DOCKER, 'service', 'rm', $job]);   // Reste eines früheren Laufs
    sleep(1);
    $cmd = 'echo "== $(hostname)"; docker container prune -f --filter label=com.docker.swarm.service.id';
    if ($images) $cmd .= '; docker image prune -f';
    [$rc, $out] = sv_run([
        $DOCKER, 'service', 'create', '--detach', '--name', $job, '--mode', 'global-job',
        '--mount', 'type=bind,src=/var/run/docker.sock,dst=/var/run/docker.sock',
        '--label', 'swarm-view.cleanup=1', '--hostname', '{{.Node.Hostname}}',
        'docker:cli', 'sh', '-c', $cmd,
    ], null, [], 60);
    if ($rc !== 0) return [false, "Aufräum-Job konnte nicht gestartet werden:\n$out"];

    $done = false;
    for ($i = 0; $i < max(1, intdiv($wait, 2)) && !$done; $i++) {
        sleep(2);
        [$prc, $ps] = sv_run([$DOCKER, 'service', 'ps', $job, '--format', '{{.CurrentState}}']);
        $states = array_filter(explode("\n", $ps));
        $done = $prc === 0 && $states && !array_filter($states, fn($st) => !preg_match('/^(Complete|Failed|Rejected|Shutdown)/i', $st));
    }
    [, $jl] = sv_run([$DOCKER, 'service', 'logs', '--no-task-ids', '--raw', $job], null, [], 20);
    if ($done) {
        sv_run([$DOCKER, 'service', 'rm', $job]);
        return [true, "Aufräum-Job auf allen Nodes fertig:\n\n" . trim($jl)];
    }
    return [true, "Aufräum-Job läuft noch (z. B. Image-Download auf dem Pi) – er wird beim nächsten Aufräumen entfernt. Bisherige Ausgabe:\n\n" . trim($jl)];
}

/** Cron-Datei schreiben: Aufräumen nach Zeitplan + Wächter jede Minute (wenn eine Wächter-Funktion aktiv ist) */
function sv_cron_apply(array $cfg): string {
    global $CRON_FILE;
    $lines = ['# Swarm View'];
    $msg = [];
    $when = ['daily' => '17 4 * * *', 'weekly' => '17 4 * * 0'][$cfg['CLEANUP_SCHEDULE']] ?? '';
    if ($when) {
        $lines[] = "$when /usr/bin/php -q /usr/local/emhttp/plugins/swarm-view/scripts/cleanup.php > /dev/null 2>&1";
        $msg[] = "Automatisches Aufräumen: {$cfg['CLEANUP_SCHEDULE']} (04:17)";
    } else {
        $msg[] = 'Automatisches Aufräumen: aus';
    }
    if (sv_monitor_needed($cfg)) {
        $lines[] = "* * * * * /usr/bin/php -q /usr/local/emhttp/plugins/swarm-view/scripts/monitor.php > /dev/null 2>&1";
        $msg[] = 'Hintergrund-Wächter: aktiv (jede Minute)';
    }
    if (count($lines) > 1) file_put_contents($CRON_FILE, implode("\n", $lines) . "\n"); else @unlink($CRON_FILE);
    if (is_executable('/usr/local/sbin/update_cron')) exec('/usr/local/sbin/update_cron');
    return implode("\n", $msg);
}

function sv_monitor_needed(array $cfg): bool {
    return $cfg['NOTIFY_ENABLED'] === 'yes' || $cfg['UPDATE_CHECK'] === 'yes' || $cfg['REBALANCE'] === 'yes';
}

/* ---------- Zustand (RAM, /tmp) ---------- */

function sv_state_read(string $name): array {
    global $STATE_DIR;
    $f = "$STATE_DIR/$name.json";
    $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    return is_array($d) ? $d : [];
}

function sv_state_write(string $name, array $data): void {
    global $STATE_DIR;
    @mkdir($STATE_DIR, 0755, true);
    $tmp = "$STATE_DIR/.$name.json." . getmypid();
    file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    rename($tmp, "$STATE_DIR/$name.json");
}

/* ---------- Unraid-Benachrichtigung ---------- */

function sv_notify(string $subject, string $desc, string $level = 'normal', string $message = ''): bool {
    global $NOTIFY;
    if (!in_array($level, ['normal', 'warning', 'alert'], true)) $level = 'normal';
    $args = [$NOTIFY, '-e', 'Swarm View', '-s', $subject, '-d', $desc, '-i', $level, '-l', '/Docker'];
    if ($message !== '') array_push($args, '-m', $message);
    [$rc] = sv_run($args, null, [], 20);
    return $rc === 0;
}

/* ---------- CPU/RAM-Agent (globaler Service, Ausgabe über service logs) ---------- */

/** Shell-Schleife des Agents: Node-CPU/RAM aus /proc + docker stats, eine JSON-Zeile pro Durchlauf */
function sv_agent_script(): string {
    return <<<'SH'
ptot=""; pidle=""
while true; do
  set -- $(head -n1 /proc/stat); shift
  tot=0; for v in "$@"; do tot=$((tot + v)); done
  idle=$(($4 + $5)); cpu=0
  if [ -n "$ptot" ] && [ $((tot - ptot)) -gt 0 ]; then cpu=$((1000 * ((tot - ptot) - (idle - pidle)) / (tot - ptot))); fi
  ptot=$tot; pidle=$idle
  mt=$(awk '/^MemTotal:/{print $2}' /proc/meminfo); ma=$(awk '/^MemAvailable:/{print $2}' /proc/meminfo)
  st=$(docker stats --no-stream --format '{{json .}}' 2>/dev/null | paste -sd, -)
  # Ohne Speicher-cgroup (Raspberry Pi) meldet docker stats "0B / 0B" – dann RSS der Prozesse je Container zählen (KiB)
  rss=""
  case "$st" in *'"MemUsage":"0B / 0B"'*)
    for n in $(docker ps --format '{{.Names}}'); do
      kb=$(docker top "$n" -o pid,rss 2>/dev/null | awk 'NR>1{s+=$2} END{print s+0}')
      rss="$rss${rss:+,}\"$n\":${kb:-0}"
    done;;
  esac
  echo "{\"node\":\"$NODE\",\"ts\":$(date +%s),\"cpu\":$cpu,\"ncpu\":$(nproc),\"memTotal\":$mt,\"memAvail\":$ma,\"stats\":[$st],\"rss\":{$rss}}"
  sleep "$INTERVAL"
done
SH;
}

/** Agent je nach Einstellung anlegen, aktualisieren oder entfernen */
const SV_AGENT_VERSION = '2'; // bei Änderungen am Agent-Skript erhöhen → Agent wird neu angelegt

function sv_agent_apply(array $cfg): string {
    global $DOCKER;
    $want = $cfg['STATS'] === 'yes';
    $interval = (string)max(5, min(300, (int)$cfg['STATS_INTERVAL']));
    [$rc, $out] = sv_run([$DOCKER, 'service', 'inspect', SV_AGENT, '--format', '{{index .Spec.Labels "swarm-view.agent.interval"}}/{{index .Spec.Labels "swarm-view.agent.version"}}']);
    $exists = $rc === 0;
    if (!$want) {
        if ($exists) sv_run([$DOCKER, 'service', 'rm', SV_AGENT]);
        return 'CPU/RAM-Agent: aus';
    }
    if ($exists && trim($out) === "$interval/" . SV_AGENT_VERSION) return "CPU/RAM-Agent: aktiv (alle {$interval} s)";
    if ($exists) sv_run([$DOCKER, 'service', 'rm', SV_AGENT]);
    [$rc, $out] = sv_run([
        $DOCKER, 'service', 'create', '--detach', '--name', SV_AGENT, '--mode', 'global',
        '--mount', 'type=bind,src=/var/run/docker.sock,dst=/var/run/docker.sock',
        '--env', 'NODE={{.Node.Hostname}}', '--env', "INTERVAL=$interval",
        '--hostname', '{{.Node.Hostname}}',
        '--label', 'swarm-view.agent=1', '--label', "swarm-view.agent.interval=$interval", '--label', 'swarm-view.agent.version=' . SV_AGENT_VERSION,
        '--log-driver', 'json-file', '--log-opt', 'max-size=256k', '--log-opt', 'max-file=1',
        '--limit-memory', '64M', '--restart-condition', 'any',
        'docker:cli', 'sh', '-c', sv_agent_script(),
    ], null, [], 60);
    return $rc === 0 ? "CPU/RAM-Agent: gestartet (alle {$interval} s)" : "CPU/RAM-Agent konnte nicht gestartet werden:\n$out";
}

/** Letzte Messwerte aller Nodes aus den Agent-Logs */
function sv_agent_read(int $interval): array {
    global $DOCKER;
    // Ist ein Node nicht erreichbar, endet "service logs" mit Fehler – die Zeilen der übrigen Nodes stehen trotzdem in der Ausgabe.
    // Deshalb den Rückgabewert ignorieren und alles auswerten, was als JSON lesbar ist.
    [, $out] = sv_run([$DOCKER, 'service', 'logs', '--raw', '--tail', '1', SV_AGENT], null, [], 15);
    $nodes = [];
    foreach (explode("\n", $out) as $line) {
        $d = json_decode(trim($line), true);
        if (!is_array($d) || empty($d['node'])) continue;
        if (isset($nodes[$d['node']]) && $nodes[$d['node']]['ts'] >= ($d['ts'] ?? 0)) continue;
        $nodes[$d['node']] = $d;
    }
    // Letzte bekannte Werte je Node merken: fehlt ein Node (aus, neu gestartet), bleiben seine Werte als „veraltet“ sichtbar
    $cache = sv_state_read('agent');
    foreach ($nodes as $n => $d) $cache[$n] = $d;
    foreach ($cache as $n => $d) if (time() - (int)($d['ts'] ?? 0) > 86400) unset($cache[$n]);
    if ($nodes) sv_state_write('agent', $cache);
    foreach ($cache as $n => &$d) $d['stale'] = (time() - (int)($d['ts'] ?? 0)) > 3 * $interval + 10;
    unset($d);
    return $cache;
}

/** Swarm-Cluster-Werte aus docker info */
function sv_swarm_settings(): array {
    global $DOCKER;
    [$rc, $out] = sv_run([$DOCKER, 'info', '--format', '{{json .Swarm}}']);
    $sw = $rc === 0 ? (json_decode($out, true) ?: []) : [];
    $spec = $sw['Cluster']['Spec'] ?? [];
    return [
        'active'       => ($sw['LocalNodeState'] ?? '') === 'active',
        'manager'      => !empty($sw['ControlAvailable']),
        'historyLimit' => $spec['Orchestration']['TaskHistoryRetentionLimit'] ?? null,
        'heartbeat'    => isset($spec['Dispatcher']['HeartbeatPeriod']) ? (int)round($spec['Dispatcher']['HeartbeatPeriod'] / 1e9) : null,
    ];
}

/** "repo:tag@sha256:abcd…" → "tag (abcd1234)" – kurze Versionsangabe für Rollback-Beschriftungen */
function sv_short_ref(string $img): string {
    $dg = '';
    if (($p = strpos($img, '@')) !== false) { $dg = substr($img, $p + 1); $img = substr($img, 0, $p); }
    $last = substr($img, (int)strrpos($img, '/'));
    $tag = strpos($last, ':') !== false ? substr($last, strrpos($last, ':') + 1) : 'latest';
    return $tag . ($dg ? ' (' . substr(preg_replace('/^sha256:/', '', $dg), 0, 8) . ')' : '');
}
