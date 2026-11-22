<?php
/* Swarm View – shared helpers for api.php, actions.php and the pages */

// via $GLOBALS because Unraid renders .page files inside a function
$GLOBALS['DOCKER']     = getenv('SV_DOCKER') ?: '/usr/bin/docker';
$GLOBALS['STACK_ROOT'] = getenv('SV_STACK_ROOT') ?: '/boot/config/plugins/swarm-view/stacks';
$GLOBALS['STACK_FILE'] = 'docker-stack.yml';
$GLOBALS['CFG_FILE']   = getenv('SV_CFG') ?: '/boot/config/plugins/swarm-view/swarm-view.cfg';
$GLOBALS['CRON_FILE']  = dirname($GLOBALS['CFG_FILE']) . '/cleanup.cron';
$GLOBALS['STATE_DIR']  = getenv('SV_STATE') ?: '/tmp/swarm-view';
$GLOBALS['NOTIFY']     = getenv('SV_NOTIFY') ?: '/usr/local/emhttp/plugins/dynamix/scripts/notify';
$GLOBALS['LOG_FILE']   = getenv('SV_LOG') ?: $GLOBALS['STATE_DIR'] . '/swarm-view.log';
const SV_AGENT = 'swarm-view-agent';
require_once __DIR__ . '/i18n.php';

/** Current time (can be overridden with SV_NOW in tests) */
function sv_now(): int {
    return (int)(getenv('SV_NOW') ?: time());
}

/** Plugin settings with default values */
const SV_DEFAULTS = [
    'LANGUAGE'         => 'auto',    // auto (Unraid's language) | en | de
    'REMOTE_ROWS'      => 'always',  // always | advanced | never
    'HOST_COLUMN'      => 'yes',
    'SWARM_POSITION'   => 'bottom', // top | middle | bottom – position of the Swarm section on the Docker page
    'ORPHAN_IMAGES'    => 'swarm', // show | swarm | hide – orphan images in Unraid's list
    'FOLDER_LOAD'      => 'total', // total | local – CPU/RAM of Folder View folders
    'REFRESH'          => '15',      // seconds
    'DEPLOY_PRUNE'     => 'yes',
    'CLEANUP_SCHEDULE' => 'off',     // off | daily | weekly
    'CLEANUP_IMAGES'   => 'yes',

    // update hints
    'UPDATE_CHECK'     => 'no',
    'UPDATE_INTERVAL'  => '12',      // hours

    // notifications
    'NOTIFY_ENABLED'       => 'no',
    'NOTIFY_NODE'          => 'yes',
    'NOTIFY_NODE_DELAY'    => '2',       // minutes offline before notifying
    'NOTIFY_NODE_LEVEL'    => 'alert',
    'NOTIFY_CRASH'         => 'yes',
    'NOTIFY_CRASH_COUNT'   => '3',       // failed starts …
    'NOTIFY_CRASH_WINDOW'  => '10',      // … within minutes
    'NOTIFY_CRASH_LEVEL'   => 'warning',
    'NOTIFY_PENDING'       => 'yes',
    'NOTIFY_PENDING_DELAY' => '5',       // minutes not placeable
    'NOTIFY_PENDING_LEVEL' => 'warning',
    'NOTIFY_MOVED'         => 'no',      // service failed over to another node / returned
    'NOTIFY_MOVED_LEVEL'   => 'normal',
    'NOTIFY_UPDATES'       => 'yes',
    'NOTIFY_UPDATES_LEVEL' => 'normal',
    'NOTIFY_RECOVERY'      => 'yes',     // all-clear when a problem is resolved
    'NOTIFY_REPEAT'        => '0',       // hours until a reminder (0 = never)
    'NOTIFY_IGNORE'        => '',        // regex for service names to ignore

    // preferred node
    'REBALANCE'        => 'no',
    'REBALANCE_DELAY'  => '5',       // minutes the preferred node must be stable

    // CPU/RAM agent
    'STATS'            => 'no',
    'STATS_INTERVAL'   => '10',      // seconds

    // log (settings → Log): debug | info | warn | error – what is recorded
    'LOG_LEVEL'        => 'info',
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
 * Position of the Swarm section on the Docker page. SwarmView.page reads its menu rank via
 *   Menu="/boot/config/plugins/swarm-view/menu.cfg MENU=Docker:3"
 * from this file (Unraid's PageBuilder: get_file_key). Pages are sorted naturally by "<rank><page name>":
 *   Docker:0 → before "1DockerContainers" · Docker:1 → after "1DockerContainers" ("S" > "D"), before Compose (rank 2) · Docker:3 → at the end
 */
function sv_menu_write(string $pos): void {
    global $CFG_FILE;
    $rank = ['top' => '0', 'middle' => '1', 'bottom' => '3'][$pos] ?? '3';
    @file_put_contents(dirname($CFG_FILE) . '/menu.cfg', "MENU=\"Docker:$rank\"\n");
}

/** Valid stack name? (also used as folder name and service prefix) */
function sv_valid_name(string $n): bool {
    return (bool)preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $n);
}

/** Names of all stacks managed by Swarm View (folders with docker-stack.yml) */
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

/** Parse .env (KEY=VALUE) – handles comments, "export " and quotes */
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
 * Run a command without a shell (array form → no quoting problems).
 * @return array [returncode, kombinierte Ausgabe]
 */
function sv_run(array $cmd, ?string $cwd = null, array $extraEnv = [], int $timeout = 120): array {
    $env = array_merge(getenv(), $extraEnv);
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd, $env);
    if (!is_resource($p)) return [127, sv_t('Could not start command: {0}', implode(' ', $cmd))];
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

/* ---------- Node selection (runtime only, until the next Stack Up) ---------- */

/** Constraints that bind a service to a node (hostname/role/id) */
function sv_is_node_constraint(string $c): bool {
    return (bool)preg_match('/^\s*node\.(hostname|role|id)\s*(==|!=)/', $c);
}

/** Node from a hostname constraint ("node.hostname == X" in any spelling) */
function sv_constraint_node(array $cons): string {
    foreach ($cons as $c) {
        if (preg_match('/^\s*node\.hostname\s*==\s*(\S+)\s*$/', $c, $m)) return $m[1];
    }
    return '';
}

/* ---------- Cleanup (button, settings and cron) ---------- */

/**
 * Starts a one-off job on every node that deletes stopped Swarm containers
 * (and optionally unused images). Waits at most $wait seconds.
 * @return array [ok, log]
 */
function sv_cleanup(bool $images, int $wait = 40): array {
    global $DOCKER;
    $job = 'swarm-view-cleanup';
    sv_run([$DOCKER, 'service', 'rm', $job]);   // leftovers of an earlier run
    sleep(1);
    $cmd = 'echo "== $(hostname)"; docker container prune -f --filter label=com.docker.swarm.service.id';
    if ($images) $cmd .= '; docker image prune -f';
    [$rc, $out] = sv_run([
        $DOCKER, 'service', 'create', '--detach', '--name', $job, '--mode', 'global-job',
        '--mount', 'type=bind,src=/var/run/docker.sock,dst=/var/run/docker.sock',
        '--label', 'swarm-view.cleanup=1', '--hostname', '{{.Node.Hostname}}',
        'docker:cli', 'sh', '-c', $cmd,
    ], null, [], 60);
    if ($rc !== 0) return [false, sv_t('Cleanup job could not be started:') . "\n$out"];

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
        return [true, sv_t('Cleanup job finished on all nodes:') . "\n\n" . trim($jl)];
    }
    return [true, sv_t('The cleanup job is still running (e.g. an image download on a slow node) – it is removed at the next cleanup. Output so far:') . "\n\n" . trim($jl)];
}

/** Write the cron file: scheduled cleanup + watchdog every minute (if a watchdog feature is on) */
function sv_cron_apply(array $cfg): string {
    global $CRON_FILE;
    $lines = ['# Swarm View'];
    $msg = [];
    $when = ['daily' => '17 4 * * *', 'weekly' => '17 4 * * 0'][$cfg['CLEANUP_SCHEDULE']] ?? '';
    if ($when) {
        $lines[] = "$when /usr/bin/php -q /usr/local/emhttp/plugins/swarm-view/scripts/cleanup.php > /dev/null 2>&1";
        $msg[] = sv_t('Automatic cleanup: {0} (04:17)', sv_t($cfg['CLEANUP_SCHEDULE']));
    } else {
        $msg[] = sv_t('Automatic cleanup: off');
    }
    if (sv_monitor_needed($cfg)) {
        $lines[] = "* * * * * /usr/bin/php -q /usr/local/emhttp/plugins/swarm-view/scripts/monitor.php > /dev/null 2>&1";
        $msg[] = sv_t('Background watchdog: active (every minute)');
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

/* ---------- Log (RAM, settings → Log) ---------- */

const SV_LOG_LEVELS = ['debug' => 0, 'info' => 1, 'warn' => 2, 'error' => 3];
const SV_LOG_MAX = 1048576;      // bytes; above that the oldest half is dropped

/**
 * One log entry: sv_log(level, source, translated message, optional details).
 * $src: watchdog | action | settings | agent. Entries below LOG_LEVEL are not written.
 */
function sv_log(string $level, string $src, string $msg, ?string $detail = null): void {
    global $LOG_FILE;
    if (!isset(SV_LOG_LEVELS[$level])) $level = 'info';
    $min = SV_LOG_LEVELS[sv_cfg()['LOG_LEVEL']] ?? 1;
    if (SV_LOG_LEVELS[$level] < $min) return;
    $e = ['t' => sv_now(), 'l' => $level, 's' => $src, 'm' => $msg];
    $detail = trim((string)$detail);
    if ($detail !== '' && $detail !== $msg) $e['d'] = mb_substr($detail, 0, 4000);
    @mkdir(dirname($LOG_FILE), 0755, true);
    $fh = @fopen($LOG_FILE, 'a+');
    if (!$fh) return;
    flock($fh, LOCK_EX);
    fwrite($fh, json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
    fflush($fh);
    $size = fstat($fh)['size'] ?? 0;
    if ($size > SV_LOG_MAX) {
        // keep the newer half, starting at a line boundary
        fseek($fh, $size - intdiv(SV_LOG_MAX, 2));
        fgets($fh);
        $rest = stream_get_contents($fh);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, $rest);
    }
    flock($fh, LOCK_UN);
    fclose($fh);
}

/** Last $limit entries (newest last), at least level $min */
function sv_log_read(int $limit = 500, string $min = 'debug'): array {
    global $LOG_FILE;
    $minN = SV_LOG_LEVELS[$min] ?? 0;
    $lines = is_file($LOG_FILE) ? (@file($LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
    $res = [];
    for ($i = count($lines) - 1; $i >= 0 && count($res) < $limit; $i--) {
        $e = json_decode($lines[$i], true);
        if (!is_array($e) || (SV_LOG_LEVELS[$e['l'] ?? ''] ?? 1) < $minN) continue;
        $res[] = $e;
    }
    return array_reverse($res);
}

function sv_log_clear(): bool {
    global $LOG_FILE;
    return !is_file($LOG_FILE) || @file_put_contents($LOG_FILE, '') !== false;
}

/* ---------- Return to the preferred node: bookkeeping ---------- */

/**
 * Forget returns in progress (watchdog state), holding the watchdog lock.
 * $filter(name) selects the services (null = all). With $release the temporary
 * node.hostname constraint of the return is removed again (otherwise the caller resets the spec itself).
 * @return string[] names of the services
 */
function sv_rebalance_forget(?callable $filter, bool $release): array {
    global $DOCKER, $STATE_DIR;
    @mkdir($STATE_DIR, 0755, true);
    $lock = @fopen("$STATE_DIR/monitor.lock", 'c');
    if ($lock) flock($lock, LOCK_EX);
    $state = sv_state_read('monitor');
    $done = [];
    foreach ($state['rebalance'] ?? [] as $name => $ph) {
        if ($filter && !$filter($name)) continue;
        if ($release) {
            [$rc, $out] = sv_run([$DOCKER, 'service', 'inspect', $name, '--format', '{{json .Spec.TaskTemplate.Placement.Constraints}}']);
            $cons = $rc === 0 ? (json_decode($out, true) ?: []) : [];
            foreach ($cons as $c) {
                if (sv_constraint_node([$c]) === ($ph['node'] ?? '')) sv_run([$DOCKER, 'service', 'update', '--detach', '--constraint-rm', $c, $name]);
            }
        }
        unset($state['rebalance'][$name], $state['rebalanceFail'][$name]);
        $done[] = $name;
    }
    if ($done) sv_state_write('monitor', $state);
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    return $done;
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

/* ---------- CPU/RAM agent (global service, output via service logs) ---------- */

/** The agent's shell loop: node CPU/RAM from /proc + docker stats, one JSON line per round */
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
  # without memory cgroup (Raspberry Pi) docker stats reports "0B / 0B" – then sum the processes' RSS per container (KiB)
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

/** Create, update or remove the agent according to the settings */
const SV_AGENT_VERSION = '2'; // increase when the agent script changes → the agent is recreated

function sv_agent_apply(array $cfg, ?bool &$changed = null): string {
    $changed = false;
    global $DOCKER;
    $want = $cfg['STATS'] === 'yes';
    $interval = (string)max(5, min(300, (int)$cfg['STATS_INTERVAL']));
    [$rc, $out] = sv_run([$DOCKER, 'service', 'inspect', SV_AGENT, '--format', '{{index .Spec.Labels "swarm-view.agent.interval"}}/{{index .Spec.Labels "swarm-view.agent.version"}}']);
    $exists = $rc === 0;
    if (!$want) {
        if ($exists) sv_run([$DOCKER, 'service', 'rm', SV_AGENT]);
        $changed = $exists;
        return sv_t('CPU/RAM agent: off');
    }
    if ($exists && trim($out) === "$interval/" . SV_AGENT_VERSION) return sv_t('CPU/RAM agent: active (every {0} s)', $interval);
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
    $changed = true;
    return $rc === 0 ? sv_t('CPU/RAM agent: started (every {0} s)', $interval) : sv_t('CPU/RAM agent could not be started:') . "\n$out";
}

/** Latest values of all nodes from the agent's logs */
function sv_agent_read(int $interval): array {
    global $DOCKER;
    // if a node is unreachable, "service logs" ends with an error – the lines of the other nodes are still in the output.
    // so ignore the return code and evaluate everything that parses as JSON.
    [, $out] = sv_run([$DOCKER, 'service', 'logs', '--raw', '--tail', '1', SV_AGENT], null, [], 15);
    $nodes = [];
    foreach (explode("\n", $out) as $line) {
        $d = json_decode(trim($line), true);
        if (!is_array($d) || empty($d['node'])) continue;
        if (isset($nodes[$d['node']]) && $nodes[$d['node']]['ts'] >= ($d['ts'] ?? 0)) continue;
        $nodes[$d['node']] = $d;
    }
    // remember the last known values per node: if a node is missing (off, rebooting) its values stay visible as "stale"
    $cache = sv_state_read('agent');
    foreach ($nodes as $n => $d) $cache[$n] = $d;
    foreach ($cache as $n => $d) if (time() - (int)($d['ts'] ?? 0) > 86400) unset($cache[$n]);
    if ($nodes) sv_state_write('agent', $cache);
    foreach ($cache as $n => &$d) $d['stale'] = (time() - (int)($d['ts'] ?? 0)) > 3 * $interval + 10;
    unset($d);
    return $cache;
}

/** Swarm cluster values from docker info */
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

/** "repo:tag@sha256:abcd…" → "tag (abcd1234)" – short version for rollback labels */
function sv_short_ref(string $img): string {
    $dg = '';
    if (($p = strpos($img, '@')) !== false) { $dg = substr($img, $p + 1); $img = substr($img, 0, $p); }
    $last = substr($img, (int)strrpos($img, '/'));
    $tag = strpos($last, ':') !== false ? substr($last, strrpos($last, ':') + 1) : 'latest';
    return $tag . ($dg ? ' (' . substr(preg_replace('/^sha256:/', '', $dg), 0, 8) . ')' : '');
}
