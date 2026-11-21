<?php
/* Swarm View – update hints: digest in the service vs. digest in the registry */
require_once __DIR__ . '/common.php';

// load Unraid's Docker classes at file level (they create global variables).
// So this file may only be included at the top level (scripts, actions.php).
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
    // add a tag if none is given (mind a port in the registry name)
    $last = substr($img, (int)strrpos($img, '/'));
    if (strpos($last, ':') === false) $img .= ':latest';
    return [$img, $digest];
}

/** Current digest of a tag in the registry (via Unraid's own update check) */
function sv_remote_digest(string $ref): ?string {
    // test hook: fixed answers from a JSON file
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

/** All services with their full spec */
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
 * Checks all Swarm services for new image versions and stores the result.
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
        if ($cur === '') {   // without a digest (e.g. a locally built image) it cannot be checked
            $res['services'][$name] = ['ref' => $ref, 'current' => '', 'remote' => '', 'update' => false, 'note' => 'kein Digest (lokales Image?)'];
            continue;
        }
        if (!array_key_exists($ref, $cache)) $cache[$ref] = sv_remote_digest($ref);
        $remote = $cache[$ref];
        if ($remote === null) $res['errors'][] = "$ref: " . sv_t('registry not reachable or image unknown');
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

/** Result of the last check (for api.php) */
function sv_updates_cached(): array {
    return sv_state_read('updates');
}
