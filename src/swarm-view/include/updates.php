<?php
/* Swarm View – Update-Hinweise: Digest im Service vs. Digest in der Registry */
require_once __DIR__ . '/common.php';

// Unraids Docker-Klassen auf Dateiebene laden (sie legen globale Variablen an).
// Diese Datei darf deshalb nur auf oberster Ebene eingebunden werden (Skripte, actions.php).
if (!getenv('SV_FAKE_REGISTRY')) {
    $docroot ??= '/usr/local/emhttp';
    if (is_file("$docroot/plugins/dynamix.docker.manager/include/DockerClient.php")) {
        require_once "$docroot/plugins/dynamix.docker.manager/include/DockerClient.php";
    }
}

/** "repo:tag@sha256:…" → ["repo:tag", "sha256:…"] */
function sv_image_parts(string $img): array {
    $digest = '';
    if (($p = strpos($img, '@')) !== false) {
        $digest = substr($img, $p + 1);
        $img = substr($img, 0, $p);
    }
    // Tag ergänzen, wenn keiner angegeben ist (Port im Registry-Namen beachten)
    $last = substr($img, (int)strrpos($img, '/'));
    if (strpos($last, ':') === false) $img .= ':latest';
    return [$img, $digest];
}

/** Aktueller Digest eines Tags in der Registry (über Unraids eigene Update-Prüfung) */
function sv_remote_digest(string $ref): ?string {
    // Test-Hook: feste Antworten aus einer JSON-Datei
    if ($fake = getenv('SV_FAKE_REGISTRY')) {
        $map = json_decode((string)@file_get_contents($fake), true) ?: [];
        return $map[$ref] ?? null;
    }
    if (!class_exists('DockerUpdate')) return null;
    try {
        $d = (new DockerUpdate())->getRemoteVersionV2($ref);
        return is_string($d) && str_starts_with($d, 'sha256:') ? $d : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Alle Services mit vollständiger Spec */
function sv_services_inspect(): array {
    global $DOCKER;
    [$rc, $ids] = sv_run([$DOCKER, 'service', 'ls', '-q']);
    $ids = array_filter(explode("\n", $rc === 0 ? $ids : ''));
    if (!$ids) return [];
    [$rc, $out] = sv_run(array_merge([$DOCKER, 'service', 'inspect'], array_values($ids)), null, [], 30);
    $d = $rc === 0 ? json_decode($out, true) : null;
    return is_array($d) ? $d : [];
}

/**
 * Prüft alle Swarm-Services auf neue Image-Versionen und speichert das Ergebnis.
 * @return array ['checked' => ts, 'services' => [name => [...]], 'errors' => [...]]
 */
function sv_check_updates(): array {
    $prev = sv_state_read('updates');
    $res = ['checked' => sv_now(), 'services' => [], 'errors' => [], 'notified' => $prev['notified'] ?? []];
    $cache = [];
    foreach (sv_services_inspect() as $s) {
        $name = $s['Spec']['Name'] ?? '';
        if ($name === '' || !empty($s['Spec']['Labels']['swarm-view.agent']) || !empty($s['Spec']['Labels']['swarm-view.cleanup'])) continue;
        [$ref, $cur] = sv_image_parts($s['Spec']['TaskTemplate']['ContainerSpec']['Image'] ?? '');
        if ($cur === '') {   // ohne Digest (z. B. lokal gebautes Image) nicht prüfbar
            $res['services'][$name] = ['ref' => $ref, 'current' => '', 'remote' => '', 'update' => false, 'note' => 'kein Digest (lokales Image?)'];
            continue;
        }
        if (!array_key_exists($ref, $cache)) $cache[$ref] = sv_remote_digest($ref);
        $remote = $cache[$ref];
        if ($remote === null) $res['errors'][] = "$ref: Registry nicht erreichbar oder Image unbekannt";
        $res['services'][$name] = [
            'ref'     => $ref,
            'current' => $cur,
            'remote'  => (string)$remote,
            'update'  => $remote !== null && $remote !== $cur,
        ];
    }
    sv_state_write('updates', $res);
    return $res;
}

/** Ergebnis der letzten Prüfung (für api.php) */
function sv_updates_cached(): array {
    return sv_state_read('updates');
}
