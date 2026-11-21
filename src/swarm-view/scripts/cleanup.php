<?php
/* Swarm View – automatic cleanup (via cron, see settings) */
require_once __DIR__ . '/../include/common.php';
$cfg = sv_cfg();
$sw = sv_swarm_settings();
if (!$sw['active'] || !$sw['manager']) exit(0);   // only on the swarm manager
[$ok, $log] = sv_cleanup($cfg['CLEANUP_IMAGES'] === 'yes', 300);
$summary = preg_match_all('/Total reclaimed space: (\S+)/', $log, $m) ? implode(', ', $m[1]) : 'nothing';
exec('logger -t swarm-view ' . escapeshellarg('cleanup ' . ($ok ? 'ok' : 'failed') . " – reclaimed: $summary"));
echo $log, "\n";
