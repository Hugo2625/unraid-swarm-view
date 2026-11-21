<?php
/*
 * Swarm View – Hintergrund-Wächter (läuft per Cron jede Minute, siehe scripts/monitor.php)
 *  - Benachrichtigungen: Node offline/online, Crash-Loop, nicht platzierbar, Ausweichen, Updates
 *  - Bevorzugter Node: Services mit Label swarm-view.prefer=<node> kehren nach einem Ausfall zurück
 *  - Update-Prüfung im eingestellten Intervall
 */
require_once __DIR__ . '/updates.php';

/** Ein Problem melden – mit Verzögerung, Erinnerung und Entwarnung */
class SvAlerts {
    public array $st;
    private array $cfg;
    private array $seen = [];
    public array $sent = [];   // für Tests/Log

    public function __construct(array &$state, array $cfg) {
        $this->st = &$state;
        $this->st['alerts'] ??= [];
        $this->cfg = $cfg;
    }

    /** Problem $key besteht gerade. Meldet nach $delayMin Minuten, erinnert nach NOTIFY_REPEAT Stunden. */
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

    /** Am Ende eines Laufs: nicht mehr gesehene Probleme sind behoben → ggf. Entwarnung */
    public function finish(array $handledPrefixes): void {
        foreach ($this->st['alerts'] as $key => $a) {
            $handled = false;
            foreach ($handledPrefixes as $p) if (str_starts_with($key, $p)) $handled = true;
            if (!$handled || isset($this->seen[$key])) continue;
            if ($a['notified'] && $this->cfg['NOTIFY_RECOVERY'] === 'yes') {
                $this->send('Behoben: ' . $a['subject'], 'Das Problem besteht nicht mehr.', 'normal');
            }
            unset($this->st['alerts'][$key]);
        }
    }

    public function send(string $subject, string $desc, string $level, string $msg = ''): void {
        $this->sent[] = "[$level] $subject – $desc";
        // Verlauf für die Einstellungsseite (Status)
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
    // Wie lange ist jeder Node schon durchgehend "ready"?
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
                    "Node $h ist offline", "Swarm erreicht $h nicht mehr. Services ohne festen Node werden auf andere Nodes verlegt.");
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
        // Neue Task-IDs zählen – außer der Service wurde gerade absichtlich geändert (Deploy/Update/Verschieben)
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
                    "$name startet ständig neu", "$cnt Neustarts in {$cfg['NOTIFY_CRASH_WINDOW']} Minuten." . ($err ? " Fehler: $err" : ''));
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
                $err = implode('; ', array_unique(array_filter(array_column($pend, 'error')))) ?: 'kein passender Node';
                $alerts->problem("pending:$name", (int)$cfg['NOTIFY_PENDING_DELAY'], $cfg['NOTIFY_PENDING_LEVEL'],
                    "$name kann nicht starten", "Swarm findet keinen passenden Node: $err");
            }
        }
    }

    /* ---------- Ausgewichen / zurückgekehrt ---------- */
    $state['placement'] ??= [];
    foreach ($services as $name => $s) {
        $where = array_values(array_unique(array_filter(array_map(fn($t) => $t['running'] ? $t['node'] : '', $s['tasks']))));
        sort($where);
        $before = $state['placement'][$name] ?? null;
        if ($where) $state['placement'][$name] = $where;
        if ($notifyOn && $cfg['NOTIFY_MOVED'] === 'yes' && !$ignored($name) && $before !== null && $where && $before !== $where
            && $s['updated'] < ($state['lastRun'] ?? 0) && !isset($state['rebalance'][$name])) {
            $alerts->send("$name läuft jetzt auf " . implode(', ', $where), 'Vorher: ' . implode(', ', $before), $cfg['NOTIFY_MOVED_LEVEL']);
        }
    }
    foreach (array_keys($state['placement']) as $n) if (!isset($services[$n])) unset($state['placement'][$n]);

    /* ---------- Bevorzugter Node: Rückkehr nach Ausfall ---------- */
    $state['rebalance'] ??= [];
    if ($cfg['REBALANCE'] === 'yes') {
        $arch = sv_node_archs();
        foreach ($services as $name => $s) {
            $P = $s['prefer'];
            $ours = "node.hostname==$P";
            $phase = $state['rebalance'][$name] ?? null;
            if ($P === '' || $s['global']) { unset($state['rebalance'][$name]); continue; }

            if ($phase) {
                // Schritt 2: Läuft er auf dem bevorzugten Node? → Constraint wieder entfernen (ohne Neustart)
                $onP = $s['tasks'] && !array_filter($s['tasks'], fn($t) => !($t['running'] && $t['node'] === $P));
                $timeout = $now - $phase['since'] > 600;
                // Fällt der bevorzugte Node während der Rückkehr aus: Bindung sofort lösen, damit Swarm ausweichen kann
                $pGone = !(($nodes[$P] ?? null) && $nodes[$P]['ready'] && $nodes[$P]['avail'] === 'active');
                if ($onP || $timeout || $pGone || !in_array($ours, $s['cons'], true)) {
                    if (in_array($ours, $s['cons'], true)) {
                        sv_run([$DOCKER, 'service', 'update', '--detach', '--constraint-rm', $ours, $name]);
                    }
                    $log[] = $onP ? "$name ist zurück auf $P." : ($pGone ? "$name: Rückkehr abgebrochen, $P ist nicht verfügbar." : "$name: Rückkehr auf $P abgebrochen (Zeitüberschreitung).");
                    if ($notifyOn && $cfg['NOTIFY_MOVED'] === 'yes' && $onP) $alerts->send("$name ist zurück auf $P", 'Automatische Rückkehr auf den bevorzugten Node.', $cfg['NOTIFY_MOVED_LEVEL']);
                    unset($state['rebalance'][$name]);
                }
                continue;
            }

            // Schritt 1: Voraussetzungen prüfen und Constraint setzen
            $node = $nodes[$P] ?? null;
            if (!$node || !$node['ready'] || $node['avail'] !== 'active') continue;
            if ($now - ($state['readySince'][$P] ?? $now) < (int)$cfg['REBALANCE_DELAY'] * 60) continue;
            if (array_filter($s['cons'], 'sv_is_node_constraint')) continue;          // fest platziert (Datei oder Auswahl)
            if ($s['archs'] && isset($arch[$P]) && !in_array($arch[$P], $s['archs'], true)) continue;
            $running = array_filter($s['tasks'], fn($t) => $t['running']);
            if (!$running || !array_filter($running, fn($t) => $t['node'] !== $P)) continue;   // läuft schon dort
            [$rc, $o] = sv_run([$DOCKER, 'service', 'update', '--detach', '--constraint-add', $ours, $name]);
            if ($rc === 0) {
                $state['rebalance'][$name] = ['since' => $now, 'node' => $P];
                $log[] = "$name wird auf den bevorzugten Node $P zurückverlegt.";
            } else {
                $log[] = "$name: Rückverlegung fehlgeschlagen: $o";
            }
        }
    } else {
        // Funktion abgeschaltet: offene Rückverlegungen sauber beenden
        foreach ($state['rebalance'] as $name => $ph) {
            sv_run([$DOCKER, 'service', 'update', '--detach', '--constraint-rm', "node.hostname=={$ph['node']}", $name]);
            unset($state['rebalance'][$name]);
        }
    }

    /* ---------- Update-Prüfung im Intervall ---------- */
    if ($cfg['UPDATE_CHECK'] === 'yes') {
        $upd = sv_updates_cached();
        if ($now - (int)($upd['checked'] ?? 0) >= max(1, (int)$cfg['UPDATE_INTERVAL']) * 3600) {
            $upd = sv_check_updates();
            $avail = array_keys(array_filter($upd['services'], fn($u) => $u['update']));
            $log[] = 'Update-Prüfung: ' . ($avail ? implode(', ', $avail) : 'alles aktuell');
            // Nur neu gefundene Versionen melden
            $new = array_values(array_filter($avail, fn($n) => ($upd['notified'][$n] ?? '') !== $upd['services'][$n]['remote']));
            if ($new && $notifyOn && $cfg['NOTIFY_UPDATES'] === 'yes') {
                $alerts->send(count($new) === 1 ? "Update verfügbar: {$new[0]}" : count($new) . ' Swarm-Updates verfügbar',
                    implode(', ', $new), $cfg['NOTIFY_UPDATES_LEVEL']);
            }
            foreach ($new as $n) $upd['notified'][$n] = $upd['services'][$n]['remote'];
            $upd['notified'] = array_intersect_key($upd['notified'], array_flip($avail));
            sv_state_write('updates', $upd);
        }
    }

    // Entwarnungen nur für Prüfungen, die in diesem Lauf aktiv waren
    $handled = [];
    if ($notifyOn && $cfg['NOTIFY_NODE'] === 'yes') $handled[] = 'node:';
    if ($notifyOn && $cfg['NOTIFY_CRASH'] === 'yes') $handled[] = 'crash:';
    if ($notifyOn && $cfg['NOTIFY_PENDING'] === 'yes') $handled[] = 'pending:';
    $alerts->finish($handled);
    if (!$notifyOn) $state['alerts'] = [];

    $state['lastRun'] = $now;
    // Ereignisse (Rückkehr, Update-Prüfung …) für die Statusanzeige
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
