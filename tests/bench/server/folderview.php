<?php
/* Mock of the FolderView 2/3 server endpoints */

function svtb_folders(array $S): array {
    $f = fn($name, $regex = '', $containers = [], $extra = []) => array_replace([
        'name' => $name, 'icon' => '', 'regex' => $regex, 'containers' => $containers, 'actions' => [],
        'settings' => ['preview' => 1, 'preview_hover' => true, 'preview_update' => true, 'preview_grayscale' => false,
                       'preview_webui' => false, 'preview_logs' => false, 'context' => 1, 'context_trigger' => 0,
                       'expand_tab' => false, 'expand_dashboard' => false, 'override_default_actions' => false, 'preview_border' => true],
    ], $extra);
    switch ($S['folders']) {
        case 'none':
            return [];
        case 'alias-regex':      // matches display names only (HomeAssistant, Zigbee2MQTT, ESPHome)
            return ['f1' => $f('Smart Home', '^(HomeAssistant|Zigbee2MQTT|ESPHome)$'), 'f2' => $f('Databases', '', ['mariadb'])];
        case 'all-remote':       // explicit members that only exist on the Pi
            return ['f1' => $f('On the Pi', '', ['smarthome_mosquitto.1', 'paperless_broker.1', 'Paperless'])];
        default:
            return [
                'f1' => $f('Databases', '', ['mariadb']),
                'f2' => $f('Smart Home', '^smarthome_'),
                'f3' => $f('Paperless', '^paperless'),
                'f4' => $f('Web', '', ['nextcloud', 'swag']),
                'f5' => $f('Monitoring', '^monitoring_'),
            ];
    }
}

function svtb_folderview(string $plugin, string $ep, array $S): void {
    $local = svtb_local($S);
    $names = array_column($local, 'name');
    // FV3 answers with a JSON content type, FV2 with text/html (it runs JSON.parse itself)
    header('Content-Type: ' . ($plugin === 'folder.view3' ? 'application/json' : 'text/html; charset=UTF-8'));
    switch ($ep) {
        case 'version':   header('Content-Type: text/plain'); echo $plugin === 'folder.view3' ? '2026.11.17' : '2025.05.26'; return;
        case 'cpu':       header('Content-Type: text/plain'); echo 12; return;
        case 'read':      echo ($_GET['type'] ?? '') === 'docker' ? json_encode((object)svtb_folders($S)) : '{}'; return;
        case 'read_order': echo '[]'; return;
        case 'read_unraid_order': echo json_encode(($_GET['type'] ?? '') === 'docker' ? $names : []); return;
        case 'read_settings': case 'read_css_config': echo '{}'; return;
        case 'read_organizer_registry': echo '{}'; return;
        case 'read_autostart': echo json_encode(['mode' => 'off', 'sequence' => [], 'autostart' => [], 'apiFile' => '']); return;
        case 'docker_load': header('Content-Type: text/plain'); echo ''; return;
        case 'read_membership': echo '{}'; return;
        case 'read_info':
            if (($_GET['type'] ?? '') !== 'docker') { echo '{}'; return; }
            $info = [];
            foreach ($local as $c) {
                $info[$c['name']] = [
                    'Id' => str_pad($c['id'], 64, '0'), 'Names' => ['/' . $c['name']], 'Image' => $c['image'], 'ImageID' => 'sha256:' . str_pad($c['id'], 64, '1'),
                    'Labels' => (object)$c['labels'], 'State' => $c['running'] ? 'running' : 'exited', 'Status' => $c['running'] ? 'Up 3 hours' : 'Exited',
                    'shortId' => $c['id'], 'shortImageId' => substr($c['id'], 0, 12),
                    'info' => ['Name' => $c['name'], 'Shell' => 'sh', 'template' => null,
                               'State' => ['Running' => $c['running'], 'Paused' => false, 'Autostart' => $c['autostart'], 'Updated' => true,
                                           'manager' => $c['manager'] ?: false, 'WebUi' => '', 'TSWebUi' => ''],
                               'Config' => ['Image' => $c['image']], 'Ports' => []],
                ];
            }
            echo json_encode((object)$info);
            return;
        default:          // writes (update/create/delete/sync_order/update_order …)
            echo json_encode(['ok' => true]);
    }
}
