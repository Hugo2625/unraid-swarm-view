<?php
/* Swarm View – after install/update: bring the agent to the current script version (only if enabled) */
require_once __DIR__ . '/../include/common.php';
$cfg = sv_cfg();
// make sure the position of the Swarm section (menu.cfg) exists
if (!is_file(dirname($GLOBALS['CFG_FILE']) . '/menu.cfg') && is_file($GLOBALS['CFG_FILE'])) sv_menu_write($cfg['SWARM_POSITION']);
if ($cfg['STATS'] !== 'yes') exit(0);
$sw = sv_swarm_settings();
if (!$sw['active'] || !$sw['manager']) exit(0);
echo sv_agent_apply($cfg), "\n";
