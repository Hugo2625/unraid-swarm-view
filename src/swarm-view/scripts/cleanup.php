<?php
/* Swarm View – automatisches Aufräumen (per Cron, siehe Einstellungen) */
require_once __DIR__ . '/../include/common.php';
$cfg = sv_cfg();
$sw = sv_swarm_settings();
if (!$sw['active'] || !$sw['manager']) exit(0);   // nur auf dem Swarm-Manager
[$ok, $log] = sv_cleanup($cfg['CLEANUP_IMAGES'] === 'yes', 300);
$summary = preg_match_all('/Total reclaimed space: (\S+)/', $log, $m) ? implode(', ', $m[1]) : 'nichts';
exec('logger -t swarm-view ' . escapeshellarg("Aufräumen " . ($ok ? 'ok' : 'fehlgeschlagen') . " – freigegeben: $summary"));
echo $log, "\n";
