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
    $state = sv_state_read('monitor');
    $now = sv_now();

    $sw = sv_swarm_settings();
    if (!$sw['active'] || !$sw['manager']) return ['Kein aktiver Swarm-Manager – nichts zu tun.'];

    /* ---------- Daten sammeln ---------- */
    [$rc, $out] = sv_run([$DOCKER, 'node', 'ls', '--format', '{{json .}}']);
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
        if (!$state['restarts'][$name]) unset($state['restarts'][$name]);

        if ($notifyOn && $cfg['NOTIFY_CRASH'] === 'yes' && !$ignored($name)) {
            $cnt = count($state['restarts'][$name] ?? []);
            if ($cnt >= max(2, (int)$cfg['NOTIFY_CRASH_COUNT'])) {
                $err = implode('; ', array_unique(array_filter(array_column($s['tasks'], 'error'))));
                $alerts->problem("crash:$name", 0, $cfg['NOTIFY_CRASH_LEVEL'],
                    sv_t('{0} keeps restarting', $name), sv_t('{0} restarts in {1} minutes.', $cnt, $cfg['NOTIFY_CRASH_WINDOW']) . ($err ? ' ' . sv_t('Error: {0}', $err) : ''));
            }
        }
    }
    foreach (array_keys($state['taskSeen']) as $n) if (!isset($services[$n])) unset($state['taskSeen'][$n], $state['restarts'][$n]);

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
        if ($notifyOn && $cfg['NOTIFY_MOVED'] === 'yes' && !$ignored($name) && $before !== null && $where && $before !== $where
            && $s['updated'] < ($state['lastRun'] ?? 0) && !isset($state['rebalance'][$name])) {
            $alerts->send(sv_t('{0} now runs on {1}', $name, implode(', ', $where)), sv_t('Before: {0}', implode(', ', $before)), $cfg['NOTIFY_MOVED_LEVEL']);
        }
    }
    foreach (array_keys($state['placement']) as $n) if (!isset($services[$n])) unset($state['placement'][$n]);

    /* ---------- Preferred node: return after an outage ---------- */
    $state['rebalance'] ??= [];
    if ($cfg['REBALANCE'] === 'yes') {
        $arch = sv_node_archs();
        foreach ($services as $name => $s) {
            $P = $s['prefer'];
            $ours = "node.hostname==$P";
            $phase = $state['rebalance'][$name] ?? null;
            if ($P === '' || $s['global']) { unset($state['rebalance'][$name]); continue; }

            if ($phase) {
                // step 2: running on the preferred node? → remove the constraint again (no restart)
                $onP = $s['tasks'] && !array_filter($s['tasks'], fn($t) => !($t['running'] && $t['node'] === $P));
                $timeout = $now - $phase['since'] > 600;
                // if the preferred node fails during the return: release the binding at once so Swarm can fail over
                $pGone = !(($nodes[$P] ?? null) && $nodes[$P]['ready'] && $nodes[$P]['avail'] === 'active');
                if ($onP || $timeout || $pGone || !in_array($ours, $s['cons'], true)) {
                    if (in_array($ours, $s['cons'], true)) {
                        sv_run([$DOCKER, 'service', 'update', '--detach', '--constraint-rm', $ours, $name]);
                    }
                    $log[] = $onP ? sv_t('{0} is back on {1}.', $name, $P) : ($pGone ? sv_t('{0}: return cancelled, {1} is not available.', $name, $P) : sv_t('{0}: return to {1} cancelled (timeout).', $name, $P));
                    if ($notifyOn && $cfg['NOTIFY_MOVED'] === 'yes' && $onP) $alerts->send(sv_t('{0} is back on {1}', $name, $P), sv_t('Automatic return to the preferred node.'), $cfg['NOTIFY_MOVED_LEVEL']);
                    unset($state['rebalance'][$name]);
                }
                continue;
            }

            // step 1: check the conditions and set the constraint
            $node = $nodes[$P] ?? null;
            if (!$node || !$node['ready'] || $node['avail'] !== 'active') continue;
            if ($now - ($state['readySince'][$P] ?? $now) < (int)$cfg['REBALANCE_DELAY'] * 60) continue;
            if (array_filter($s['cons'], 'sv_is_node_constraint')) continue;          // fixed placement (file or selection)
            if ($s['archs'] && isset($arch[$P]) && !in_array($arch[$P], $s['archs'], true)) continue;
            $running = array_filter($s['tasks'], fn($t) => $t['running']);
            if (!$running || !array_filter($running, fn($t) => $t['node'] !== $P)) continue;   // already runs there
            [$rc, $o] = sv_run([$DOCKER, 'service', 'update', '--detach', '--constraint-add', $ours, $name]);
            if ($rc === 0) {
                $state['rebalance'][$name] = ['since' => $now, 'node' => $P];
                $log[] = sv_t('{0} is moved back to the preferred node {1}.', $name, $P);
            } else {
                $log[] = sv_t('{0}: moving back failed: {1}', $name, $o);
            }
        }
    } else {
        // feature switched off: finish pending returns cleanly
        foreach ($state['rebalance'] as $name => $ph) {
            sv_run([$DOCKER, 'service', 'update', '--detach', '--constraint-rm', "node.hostname=={$ph['node']}", $name]);
            unset($state['rebalance'][$name]);
        }
    }

    /* ---------- Update check at the interval ---------- */
    if ($cfg['UPDATE_CHECK'] === 'yes') {
        $upd = sv_updates_cached();
        if ($now - (int)($upd['checked'] ?? 0) >= max(1, (int)$cfg['UPDATE_INTERVAL']) * 3600) {
            $upd = sv_check_updates();
            $avail = array_keys(array_filter($upd['services'], fn($u) => $u['update']));
            $log[] = sv_t('Update check: {0}', $avail ? implode(', ', $avail) : sv_t('everything up to date'));
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
