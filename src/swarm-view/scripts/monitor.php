<?php
/* Swarm View – Hintergrund-Wächter, per Cron jede Minute (nur wenn in den Einstellungen etwas aktiv ist) */
require_once __DIR__ . '/../include/monitor.php';

$cfg = sv_cfg();
if (!sv_monitor_needed($cfg)) exit(0);

// Keine überlappenden Läufe
@mkdir($GLOBALS['STATE_DIR'], 0755, true);
$lock = fopen($GLOBALS['STATE_DIR'] . '/monitor.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);

foreach (sv_monitor_run($cfg) as $line) {
    echo $line, "\n";
    if (!str_starts_with($line, 'Gemeldet:')) exec('logger -t swarm-view ' . escapeshellarg($line));
}
