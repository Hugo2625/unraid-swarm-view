<?php
/*
 * Swarm View – log for the settings page (read-only, GET).
 * ?min=debug|info|warn|error&limit=N → {"ok":true,"level":"<LOG_LEVEL>","now":ts,"entries":[{t,l,s,m,d?}, …]} (oldest first)
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/common.php';

$min = $_GET['min'] ?? 'debug';
if (!isset(SV_LOG_LEVELS[$min])) $min = 'debug';
$limit = max(1, min(5000, (int)($_GET['limit'] ?? 1000)));
echo json_encode([
    'ok'      => true,
    'level'   => sv_cfg()['LOG_LEVEL'],
    'now'     => sv_now(),
    'entries' => sv_log_read($limit, $min),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
