<?php
/*
 * Swarm View – background watchdog (runs every minute via cron, see scripts/monitor.php)
 *  - notifications: node offline/online, crash loop, not placeable, failover, updates
 *  - preferred node: services with label swarm-view.prefer=<node> return after an outage
 *  - update check at the configured interval
 */
require_once __DIR__ . '/updates.php';

/** Report a problem – with delay, reminder and all-clear */
class SvAlerts {
    public array $st;
    private array $cfg;
    private array $seen = [];
    public array $sent = [];   // for tests/log

    public function __construct(array &$state, array $cfg) {
        $this->st = &$state;
        $this->st['alerts'] ??= [];
        $this->cfg = $cfg;
    }

    /** Problem $key exists right now. Notifies after $delayMin minutes, reminds after NOTIFY_REPEAT hours. */
    public function problem(string $key, int $delayMin, string $level, string $subject, string $desc): void {
        $this->seen[$key] = true;
        $now = sv_now();
        $a = &$this->st['alerts'][$key];
        $a ??= ['since' => $now, 'notified' => 0, 'subject' => $subject];
        $a['subject'] = $subject;
        if ($now - $a['since'] < $delayMin * 60) return;
        $repeat = (int)$this->cfg['NOTIFY_REPEAT'] * 3600;
        if ($a['notified'] && (!$repeat || $now - $a['notified'] < $repeat)) return;
        $this->send($a['notified'] ? "Erinnerung: $subject" : $subject, $desc, $level);
        $a['notified'] = $now;
    }

    /** At the end of a run: problems not seen anymore are resolved → all-clear if wanted */
    public function finish(array $handledPrefixes): void {
        foreach ($this->st['alerts'] as $key => $a) {
            $handled = false;
            foreach ($handledPrefixes as $p) if (str_starts_with($key, $p)) $handled = true;
            if (!$handled || isset($this->seen[$key])) continue;
            if ($a['notified'] && $this->cfg['NOTIFY_RECOVERY'] === 'yes') {
                $this->send(sv_t('Resolved: {0}', $a['subject']), sv_t('The problem no longer exists.'), 'normal');
            }
            unset($this->st['alerts'][$key]);
        }
    }

    public function send(string $subject, string $desc, string $level, string $msg = ''): void {
        $this->sent[] = "[$level] $subject – $desc";
        sv_log($level === 'normal' ? 'info' : 'warn', 'watchdog', sv_t('Notification: {0}', $subject), $desc);
        // history for the settings page (status)
        $this->st['history'] ??= [];
        $this->st['history'][] = ['t' => sv_now(), 'level' => $level, 'subject' => $subject, 'desc' => $desc];
        $this->st['history'] = array_slice($this->st['history'], -20);
        sv_notify($subject, $desc, $level, $msg);
    }
}

function sv_monitor_run(array $cfg, bool $verbose = false): array {
    global $DOCKER;
    $log = [];
    $t0 = microtime(true);
    $state = sv_state_read('monitor');
    $now = sv_now();
    // event: status feed + syslog + log; debug only goes to the log
    $ev = function (string $level, string $msg, ?string $detail = null) use (&$log) { $log[] = $msg; sv_log($level, 'watchdog', $msg, $detail); };
    $dbg = fn(string $msg, ?string $detail = null) => sv_log('debug', 'watchdog', $msg, $detail);

    $sw = sv_swarm_settings();
    if (!$sw['active'] || !$sw['manager']) {
        if (empty($state['noManager'])) {
            sv_log('warn', 'watchdog', sv_t('No active Swarm manager on this server – the watchdog does nothing.'));
            $state['noManager'] = 1;
            sv_state_write('monitor', $state);
        }
        return [sv_t('No active Swarm manager on this server – the watchdog does nothing.')];
    }
    if (!empty($state['noManager'])) {
        unset($state['noManager']);
        sv_log('info', 'watchdog', sv_t('Swarm manager active again – the watchdog continues.'));
    }

    /* ---------- Daten sammeln ---------- */
    [$rc, $out] = sv_run([$DOCKER, 'node', 'ls', '--format', '{{json .}}']);
    if ($rc !== 0) sv_log('error', 'watchdog', sv_t('{0} failed', 'docker node ls'), $out);
    $nodes = [];
    foreach (explode("\n", $rc === 0 ? $out : '') as $l) {
        $n = json_decode(trim($l), true);
        if (is_array($n) && !empty($n['Hostname'])) $nodes[$n['Hostname']] = [
            'ready'  => strtolower($n['Status'] ?? '') === 'ready',
            'avail'  => strtolower($n['Availability'] ?? ''),
        ];
    }
    // how long has each node been "ready" without interruption?
    $state['readySince'] ??= [];
    foreach ($nodes as $h => $n) {
        if ($n['ready']) $state['readySince'][$h] ??= $now; else unset($state['readySince'][$h]);
    }
    // node state changes for the log
    if ($rc === 0) {
        $state['nodeState'] ??= [];
        $label = ['active' => sv_t('online'), 'down' => sv_t('offline'), 'drain' => sv_t('in maintenance'), 'pause' => sv_t('paused')];
        $known = $state['nodeState'];
        foreach ($nodes as $h => $n) {
            $st = !$n['ready'] ? 'down' : ($n['avail'] ?: 'active');
            $prev = $state['nodeState'][$h] ?? null;
            if ($prev !== null && $prev !== $st) {
                sv_log($st === 'down' ? 'warn' : 'info', 'watchdog', sv_t('Node {0} is {1} (before: {2}).', $h, $label[$st] ?? $st, $label[$prev] ?? $prev));
            } elseif ($prev === null && $known) {
                sv_log('info', 'watchdog', sv_t('Node {0} joined the swarm ({1}).', $h, $label[$st] ?? $st));
            }
            $state['nodeState'][$h] = $st;
        }
        foreach (array_keys($state['nodeState']) as $h) {
            if (!isset($nodes[$h])) { sv_log('info', 'watchdog', sv_t('Node {0} left the swarm.', $h)); unset($state['nodeState'][$h]); }
        }
    }

    $ignore = trim($cfg['NOTIFY_IGNORE']);
    $ignored = function (string $svc) use ($ignore): bool {
        return $ignore !== '' && @preg_match('~' . str_replace('~', '\~', $ignore) . '~', $svc) === 1;
    };

    $services = [];
    foreach (sv_services_inspect() as $s) {
        $lab = $s['Spec']['Labels'] ?? [];
        if (!empty($lab['swarm-view.agent']) || !empty($lab['swarm-view.cleanup'])) continue;
        $name = $s['Spec']['Name'];
        $services[$name] = [
            'id'      => $s['ID'],
            'global'  => isset($s['Spec']['Mode']['Global']),
            'prefer'  => trim($lab['swarm-view.prefer'] ?? ''),
            'cons'    => $s['Spec']['TaskTemplate']['Placement']['Constraints'] ?? [],
            'archs'   => array_values(array_filter(array_map(fn($p) => $p['Architecture'] ?? '', $s['Spec']['TaskTemplate']['Placement']['Platforms'] ?? []))),
            'updated' => strtotime($s['UpdatedAt'] ?? '') ?: 0,
            'tasks'   => [],
        ];
    }
    if ($services) {
        [$rc, $out] = sv_run(array_merge([$DOCKER, 'service', 'ps', '--no-trunc', '--filter', 'desired-state=running', '--format', '{{json .}}'], array_keys($services)), null, [], 30);
        if ($rc !== 0) sv_log('error', 'watchdog', sv_t('{0} failed', 'docker service ps'), $out);
        foreach (explode("\n", $rc === 0 ? $out : '') as $l) {
            $t = json_decode(trim($l), true);
            if (!is_array($t)) continue;
            $tn = $t['Name'] ?? '';
            $sn = substr($tn, 0, (int)strrpos($tn, '.'));
            if (!isset($services[$sn])) continue;
            $services[$sn]['tasks'][] = [
                'id'      => $t['ID'] ?? '',
                'node'    => $t['Node'] ?? '',
                'running' => (bool)preg_match('/^Running/i', $t['CurrentState'] ?? ''),
                'pending' => ($t['Node'] ?? '') === '' || (bool)preg_match('/^Pending/i', $t['CurrentState'] ?? ''),
                'error'   => $t['Error'] ?? '',
            ];
        }
    }

    $alerts = new SvAlerts($state, $cfg);
    $notifyOn = $cfg['NOTIFY_ENABLED'] === 'yes';

    /* ---------- Node offline / wieder online ---------- */
    if ($notifyOn && $cfg['NOTIFY_NODE'] === 'yes') {
        foreach ($nodes as $h => $n) {
            if (!$n['ready'] && $n['avail'] !== 'drain') {
                $alerts->problem("node:$h", (int)$cfg['NOTIFY_NODE_DELAY'], $cfg['NOTIFY_NODE_LEVEL'],
                    sv_t('Node {0} is offline', $h), sv_t('Swarm cannot reach {0} anymore. Services without a fixed node are moved to other nodes.', $h));
            }
        }
    }

    /* ---------- Crash-Loop (viele neue Tasks in kurzer Zeit) ---------- */
    $state['taskSeen'] ??= [];
    $state['restarts'] ??= [];
    $window = max(1, (int)$cfg['NOTIFY_CRASH_WINDOW']) * 60;
    foreach ($services as $name => $s) {
        $prevIds = $state['taskSeen'][$name] ?? null;
        $ids = array_column($s['tasks'], 'id');
        // count new task IDs – unless the service was just changed on purpose (deploy/update/move)
        if ($prevIds !== null && $s['updated'] < ($state['lastRun'] ?? 0)) {
            foreach (array_diff($ids, $prevIds) as $new) $state['restarts'][$name][] = $now;
        }
        $state['taskSeen'][$name] = $ids;
        $state['restarts'][$name] = array_values(array_filter($state['restarts'][$name] ?? [], fn($t) => $now - $t < $window));
        if (!$state['restarts'][$name]) unset($state['restarts'][$name], $state['crashLogged'][$name]);
        $cnt = count($state['restarts'][$name] ?? []);
        if ($cnt >= max(2, (int)$cfg['NOTIFY_CRASH_COUNT']) && empty($state['crashLogged'][$name])) {
            $state['crashLogged'][$name] = 1;
            sv_log('warn', 'watchdog', sv_t('{0} keeps restarting', $name) . ' – ' . sv_t('{0} restarts in {1} minutes.', $cnt, $cfg['NOTIFY_CRASH_WINDOW']),
                implode("\n", array_unique(array_filter(array_column($s['tasks'], 'error')))));
        }

        if ($notifyOn && $cfg['NOTIFY_CRASH'] === 'yes' && !$ignored($name)) {
            $cnt = count($state['restarts'][$name] ?? []);
            if ($cnt >= max(2, (int)$cfg['NOTIFY_CRASH_COUNT'])) {
                $err = implode('; ', array_unique(array_filter(array_column($s['tasks'], 'error'))));
                $alerts->problem("crash:$name", 0, $cfg['NOTIFY_CRASH_LEVEL'],
                    sv_t('{0} keeps restarting', $name), sv_t('{0} restarts in {1} minutes.', $cnt, $cfg['NOTIFY_CRASH_WINDOW']) . ($err ? ' ' . sv_t('Error: {0}', $err) : ''));
            }
        }
    }
    foreach (array_keys($state['taskSeen']) as $n) if (!isset($services[$n])) unset($state['taskSeen'][$n], $state['restarts'][$n], $state['crashLogged'][$n]);

    /* ---------- Not placeable: log (independent of notifications) ---------- */
    $state['pendingLogged'] ??= [];
    foreach ($services as $name => $s) {
        $pend = array_filter($s['tasks'], fn($t) => $t['pending']);
        if ($pend && empty($state['pendingLogged'][$name])) {
            $state['pendingLogged'][$name] = $now;
            sv_log('warn', 'watchdog', sv_t('{0} cannot start', $name) . ' – ' . sv_t('Swarm finds no suitable node: {0}', implode('; ', array_unique(array_filter(array_column($pend, 'error')))) ?: sv_t('no suitable node')));
        } elseif (!$pend && !empty($state['pendingLogged'][$name])) {
            unset($state['pendingLogged'][$name]);
            sv_log('info', 'watchdog', sv_t('{0} found a node again.', $name));
        }
    }
    foreach (array_keys($state['pendingLogged']) as $n) if (!isset($services[$n])) unset($state['pendingLogged'][$n]);

    /* ---------- Nicht platzierbar ---------- */
    if ($notifyOn && $cfg['NOTIFY_PENDING'] === 'yes') {
        foreach ($services as $name => $s) {
            if ($ignored($name)) continue;
            $pend = array_filter($s['tasks'], fn($t) => $t['pending']);
            if ($pend) {
                $err = implode('; ', array_unique(array_filter(array_column($pend, 'error')))) ?: sv_t('no suitable node');
                $alerts->problem("pending:$name", (int)$cfg['NOTIFY_PENDING_DELAY'], $cfg['NOTIFY_PENDING_LEVEL'],
                    sv_t('{0} cannot start', $name), sv_t('Swarm finds no suitable node: {0}', $err));
            }
        }
    }

    /* ---------- Failed over / returned ---------- */
    $state['placement'] ??= [];
    foreach ($services as $name => $s) {
        $where = array_values(array_unique(array_filter(array_map(fn($t) => $t['running'] ? $t['node'] : '', $s['tasks']))));
        sort($where);
        $before = $state['placement'][$name] ?? null;
        if ($where) $state['placement'][$name] = $where;
        if ($before !== null && $where && $before !== $where) {
            $planned = $s['updated'] >= ($state['lastRun'] ?? 0) || isset($state['rebalance'][$name]);
            sv_log($planned ? 'debug' : 'info', 'watchdog', sv_t('{0} now runs on {1}', $name, implode(', ', $where)) . ' (' . sv_t('Before: {0}', implode(', ', $before)) . ')'
                . ($planned ? ' – ' . sv_t('after a change of the service') : ''));
        }
        if ($notifyOn && $cfg['NOTIFY_MOVED'] === 'yes' && !$ignored($name) && $before !== null && $where && $before !== $where
            && $s['updated'] < ($state['lastRun'] ?? 0) && !isset($state['rebalance'][$name])) {
            $alerts->send(sv_t('{0} now runs on {1}', $name, implode(', ', $where)), sv_t('Before: {0}', implode(', ', $before)), $cfg['NOTIFY_MOVED_LEVEL']);
        }
    }
    foreach (array_keys($state['placement']) as $n) if (!isset($services[$n])) unset($state['placement'][$n]);

    /* ---------- Preferred node: return after an outage ---------- */
    $state['rebalance'] ??= [];
    $state['rebalanceFail'] ??= [];
    // services that no longer exist (only when the service list could be read)
    if ($services) foreach (['rebalance', 'rebalanceFail'] as $k) foreach (array_keys($state[$k]) as $n) if (!isset($services[$n])) unset($state[$k][$n]);
    if ($cfg['REBALANCE'] === 'yes') {
        $arch = null;
        foreach ($services as $name => $s) {
            $P = $s['prefer'];
            $phase = $state['rebalance'][$name] ?? null;
            if ($P === '' || $s['global']) {
                if ($phase) $dbg(sv_t('{0}: no preferred node anymore – return forgotten.', $name));
                unset($state['rebalance'][$name], $state['rebalanceFail'][$name]);
                continue;
            }
            $running = array_filter($s['tasks'], fn($t) => $t['running']);

            if ($phase) {
                // step 2: arrived on the target node? → remove the temporary constraint again (no restart)
                $T = (string)$phase['node'];
                $tmp = array_values(array_filter($s['cons'], fn($c) => sv_constraint_node([$c]) === $T));
                if (!$tmp) {
                    // no temporary binding (removed by hand, Stack Up …) → nothing is returning
                    unset($state['rebalance'][$name]);
                    $dbg(sv_t('{0}: return to {1} forgotten – the temporary node binding is gone.', $name, $T));
                    continue;
                }
                $onT = $s['tasks'] && !array_filter($s['tasks'], fn($t) => !($t['running'] && $t['node'] === $T));
                $age = $now - (int)$phase['since'];
                // target node fails during the return: release the binding at once so Swarm can fail over
                $tGone = !(($nodes[$T] ?? null) && $nodes[$T]['ready'] && $nodes[$T]['avail'] === 'active');
                $down = !$running && $age > 300;          // the return took the service down and it does not start on the target
                $timeout = $age > 600;
                $changed = $T !== $P;                     // preferred node changed meanwhile
                if (!$onT && !$tGone && !$down && !$timeout && !$changed) {
                    $dbg(sv_t('{0}: return to {1} in progress ({2} s).', $name, $T, $age));
                    continue;
                }
                foreach ($tmp as $c) {
                    [$rc, $o] = sv_run([$DOCKER, 'service', 'update', '--detach', '--constraint-rm', $c, $name]);
                    if ($rc !== 0) sv_log('error', 'watchdog', sv_t('{0}: the temporary node binding could not be removed.', $name), $o);
                }
                unset($state['rebalance'][$name]);
                $errs = implode("\n", array_unique(array_filter(array_column($s['tasks'], 'error'))));
                if ($onT) {
                    unset($state['rebalanceFail'][$name]);
                    $ev('info', sv_t('{0} is back on {1}.', $name, $T));
                    if ($notifyOn && $cfg['NOTIFY_MOVED'] === 'yes') $alerts->send(sv_t('{0} is back on {1}', $name, $T), sv_t('Automatic return to the preferred node.'), $cfg['NOTIFY_MOVED_LEVEL']);
                } elseif ($tGone) {
                    $ev('warn', sv_t('{0}: return cancelled, {1} is not available.', $name, $T));
                } elseif ($changed) {
                    $ev('info', sv_t('{0}: return to {1} stopped – the placement was changed.', $name, $T));
                } else {
                    // failed: do not try again every few minutes (each attempt restarts the service)
                    $f = $state['rebalanceFail'][$name] ?? [];
                    $n = (($f['node'] ?? '') === $T ? (int)($f['n'] ?? 0) : 0) + 1;
                    $wait = min(1440, 30 * 2 ** ($n - 1));
                    $state['rebalanceFail'][$name] = ['node' => $T, 'n' => $n, 'until' => $now + $wait * 60];
                    $ev('warn', ($down ? sv_t('{0} does not start on {1} – return cancelled, Swarm runs it elsewhere again.', $name, $T)
                                       : sv_t('{0}: return to {1} cancelled (timeout).', $name, $T))
                        . ' ' . sv_t('Next attempt in {0} minutes.', $wait), $errs);
                }
                continue;
            }

            // step 1: check the conditions and set the constraint
            $fail = $state['rebalanceFail'][$name] ?? null;
            if ($fail && ($fail['node'] ?? '') !== $P) { unset($state['rebalanceFail'][$name]); $fail = null; }
            if (!$running) continue;                                                   // not running at all → nothing to move
            if (!array_filter($running, fn($t) => $t['node'] !== $P)) {                 // already runs there
                if ($fail) unset($state['rebalanceFail'][$name]);
                continue;
            }
            $node = $nodes[$P] ?? null;
            if (!$node || !$node['ready'] || $node['avail'] !== 'active') { $dbg(sv_t('{0} waits for {1}: node not available.', $name, $P)); continue; }
            $stable = $now - ($state['readySince'][$P] ?? $now);
            if ($stable < (int)$cfg['REBALANCE_DELAY'] * 60) { $dbg(sv_t('{0} waits for {1}: node stable for {2} of {3} minutes.', $name, $P, intdiv($stable, 60), $cfg['REBALANCE_DELAY'])); continue; }
            if (array_filter($s['cons'], 'sv_is_node_constraint')) { $dbg(sv_t('{0}: fixed node in the constraints – no return.', $name)); continue; }
            $arch ??= sv_node_archs();
            if ($s['archs'] && isset($arch[$P]) && !in_array($arch[$P], $s['archs'], true)) { $dbg(sv_t('{0}: the image is not available for {1} ({2}).', $name, $P, $arch[$P])); continue; }
            if ($fail && $fail['until'] > $now) { $dbg(sv_t('{0}: last return to {1} failed – next attempt at {2}.', $name, $P, date('H:i', (int)$fail['until']))); continue; }
            [$rc, $o] = sv_run([$DOCKER, 'service', 'update', '--detach', '--constraint-add', "node.hostname==$P", $name]);
            if ($rc === 0) {
                $state['rebalance'][$name] = ['since' => $now, 'node' => $P];
                $ev('info', sv_t('{0} is moved back to the preferred node {1}.', $name, $P));
            } else {
                $ev('error', sv_t('{0}: moving back failed: {1}', $name, mb_substr($o, 0, 200)), $o);
            }
        }
    } elseif ($state['rebalance']) {
        // feature switched off: finish pending returns cleanly
        foreach ($state['rebalance'] as $name => $ph) {
            sv_run([$DOCKER, 'service', 'update', '--detach', '--constraint-rm', "node.hostname=={$ph['node']}", $name]);
            $ev('info', sv_t('{0}: return to {1} stopped – automatic return is switched off.', $name, $ph['node']));
        }
        $state['rebalance'] = [];
        $state['rebalanceFail'] = [];
    }

    /* ---------- Update check at the interval ---------- */
    if ($cfg['UPDATE_CHECK'] === 'yes') {
        $upd = sv_updates_cached();
        if ($now - (int)($upd['checked'] ?? 0) >= max(1, (int)$cfg['UPDATE_INTERVAL']) * 3600) {
            $upd = sv_check_updates();
            $avail = array_keys(array_filter($upd['services'], fn($u) => $u['update']));
            $ev('info', sv_t('Update check: {0}', $avail ? implode(', ', $avail) : sv_t('everything up to date')), $upd['errors'] ? implode("\n", array_unique($upd['errors'])) : null);
            // notify only newly found versions
            $new = array_values(array_filter($avail, fn($n) => ($upd['notified'][$n] ?? '') !== $upd['services'][$n]['remote']));
            if ($new && $notifyOn && $cfg['NOTIFY_UPDATES'] === 'yes') {
                $alerts->send(count($new) === 1 ? sv_t('Update available: {0}', $new[0]) : sv_t('{0} Swarm updates available', count($new)),
                    implode(', ', $new), $cfg['NOTIFY_UPDATES_LEVEL']);
            }
            foreach ($new as $n) $upd['notified'][$n] = $upd['services'][$n]['remote'];
            $upd['notified'] = array_intersect_key($upd['notified'], array_flip($avail));
            sv_state_write('updates', $upd);
        }
    }

    // all-clear only for checks that were active in this run
    $handled = [];
    if ($notifyOn && $cfg['NOTIFY_NODE'] === 'yes') $handled[] = 'node:';
    if ($notifyOn && $cfg['NOTIFY_CRASH'] === 'yes') $handled[] = 'crash:';
    if ($notifyOn && $cfg['NOTIFY_PENDING'] === 'yes') $handled[] = 'pending:';
    $alerts->finish($handled);
    if (!$notifyOn) $state['alerts'] = [];

    $state['lastRun'] = $now;
    $dbg(sv_t('Watchdog run: {0} nodes, {1} services, {2} s.', count($nodes), count($services), round(microtime(true) - $t0, 1)));
    // events (return, update check …) for the status display
    $state['events'] ??= [];
    foreach ($log as $l) $state['events'][] = ['t' => $now, 'text' => $l];
    $state['events'] = array_slice($state['events'], -20);
    sv_state_write('monitor', $state);
    return array_merge($log, array_map(fn($m) => "Gemeldet: $m", $alerts->sent));
}

/** Hostname → Architektur (amd64/arm64) */
function sv_node_archs(): array {
    global $DOCKER;
    [$rc, $out] = sv_run([$DOCKER, 'node', 'ls', '-q']);
    $ids = array_filter(explode("\n", $rc === 0 ? $out : ''));
    if (!$ids) return [];
    [$rc, $out] = sv_run(array_merge([$DOCKER, 'node', 'inspect'], array_values($ids)));
    $map = ['x86_64' => 'amd64', 'aarch64' => 'arm64', 'armv7l' => 'arm'];
    $res = [];
    foreach ((json_decode($rc === 0 ? $out : '[]', true) ?: []) as $n) {
        $a = $n['Description']['Platform']['Architecture'] ?? '';
        $res[$n['Description']['Hostname'] ?? ''] = $map[$a] ?? $a;
    }
    return $res;
}
