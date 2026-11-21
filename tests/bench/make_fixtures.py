#!/usr/bin/env python3
"""Builds scenarios/world.json: Swarm View API answer + local Docker containers of the fake Unraid."""
import json, hashlib, datetime as dt, os

HERE = os.path.dirname(os.path.abspath(__file__))
NOW = dt.datetime.now(dt.timezone.utc).replace(microsecond=0)


def tid(s, n=25):
    return hashlib.sha1(s.encode()).hexdigest()[:n]


def iso(hours):
    return (NOW - dt.timedelta(hours=hours)).isoformat().replace('+00:00', 'Z')


COLORS = ['#0082c9', '#c0765a', '#2f9e44', '#175ddc', '#18bcf2', '#7b3f98', '#e8b000', '#8f0000', '#455a64', '#9b2aff', '#f46800']


def icon(name):
    c = COLORS[int(tid(name, 4), 16) % len(COLORS)]
    svg = (f"<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 48 48'><rect width='48' height='48' rx='10' fill='{c}'/>"
           f"<text x='24' y='32' font-family='Arial' font-size='24' font-weight='bold' fill='#fff' text-anchor='middle'>{name[0].upper()}</text></svg>")
    return 'data:image/svg+xml;utf8,' + svg.replace('<', '%3C').replace('>', '%3E').replace('#', '%23').replace("'", '%27')


def svc(stack, short, image, node, ports=(), alias='', prefer='', constraints=(), replicas=1, labels=None, update=None, mounts=True):
    name = f'{stack}_{short}'
    lab = {'net.unraid.docker.icon': icon(short)}
    lab.update(labels or {})
    tasks = []
    nodes = node if isinstance(node, list) else [node] * replicas
    for i, n in enumerate(nodes, 1):
        t = tid(name + str(i))
        tasks.append({'id': t, 'slot': str(i), 'node': n, 'local': n == 'Unraid', 'current': 'Running 3 hours ago', 'error': '',
                      'created': iso(50 + i), 'since': iso(3 + i), 'containerId': tid('c' + t, 12),
                      'networks': [{'name': f'{stack}_default', 'addr': f'10.0.{len(stack)}.{10 + i + len(short)}'}]})
    return {'id': name, 'name': name, 'short': short, 'stack': stack, 'image': image, 'localImage': False, 'mode': 'replicated',
            'replicas': f'{len(nodes)}/{len(nodes)}', 'desired': len(nodes),
            'ports': ', '.join(f'{p}→{p}/tcp' for p in ports), 'constraints': list(constraints), 'platforms': ['amd64', 'arm64'],
            'labels': lab, 'prefer': prefer, 'alias': alias, 'canRollback': False, 'rollbackTo': '', 'internal': False,
            'mounts': [{'type': 'bind', 'target': '/config', 'source': f'/mnt/user/appdata/{short}', 'ro': False}] if mounts else [],
            'portList': [{'target': p, 'published': p, 'proto': 'tcp'} for p in ports],
            'tasks': tasks, 'placeNode': '', 'rebalancing': False, 'update': update}


WORKERS = [  # (hostname, arch, cpus, memGB)
    ('raspberrypi', 'aarch64', 4, 3.7),
    ('nuc', 'x86_64', 8, 15.5),
    ('pi5', 'aarch64', 4, 7.8),
]
NODE_IDS = {'Unraid': 'n1unraid0000000000000000a', 'raspberrypi': 'n2raspberrypi000000000000', 'nuc': 'n3nuc00000000000000000000', 'pi5': 'n4pi500000000000000000000'}


def build(nw):
    """nw = number of workers (1–3)"""
    workers = [w[0] for w in WORKERS[:nw]]
    w2 = workers[1] if nw >= 2 else 'raspberrypi'     # second worker (paperless lives there)
    w3 = workers[2] if nw >= 3 else w2
    stacks = [
        {'name': 'paperless', 'services': [
            svc('paperless', 'paperless-ngx', 'ghcr.io/paperless-ngx/paperless-ngx:latest', w2, [8000], alias='Paperless', update={'remote': 'x'}),
            svc('paperless', 'broker', 'redis:7', w2, mounts=False),
            svc('paperless', 'gotenberg', 'gotenberg/gotenberg:8', w2, mounts=False)]},
        {'name': 'smarthome', 'services': [
            svc('smarthome', 'homeassistant', 'ghcr.io/home-assistant/home-assistant:stable', 'Unraid', [8123], alias='HomeAssistant',
                constraints=['node.role == manager'], update={'remote': 'x'}),
            svc('smarthome', 'mosquitto', 'eclipse-mosquitto:2', 'raspberrypi', [1883, 9001]),
            svc('smarthome', 'zigbee2mqtt', 'koenkk/zigbee2mqtt:latest', 'raspberrypi', [8080], alias='Zigbee2MQTT'),
            svc('smarthome', 'node-red', 'nodered/node-red:latest', w3, [1880], prefer='raspberrypi',
                labels={'swarm-view.prefer': 'raspberrypi'}),
            svc('smarthome', 'esphome', 'ghcr.io/esphome/esphome:stable', 'Unraid', constraints=['node.hostname == Unraid'], alias='ESPHome'),
            svc('smarthome', 'whoami', 'traefik/whoami:latest', ['Unraid'] + workers, [8088], alias='whoami'),
        ]},
    ]
    if nw >= 2:   # global service: one task per node, slot = node ID
        g = svc('monitoring', 'node-exporter', 'prom/node-exporter:latest', ['Unraid'] + workers, mounts=False)
        g['mode'] = 'global'; g['desired'] = None
        for t in g['tasks']:
            t['slot'] = NODE_IDS[t['node']]
        stacks.append({'name': 'monitoring', 'services': [g]})
    stats_tasks, containers = {}, {}
    for st in stacks:
        for s in st['services']:
            for i, t in enumerate(s['tasks']):
                if t['node'] != 'Unraid':
                    w = next(x for x in WORKERS if x[0] == t['node'])
                    stats_tasks[t['id']] = {'cpu': f'{1.5 + i + len(s["short"]) % 5:.2f}%', 'mem': f'{80 + 10 * i}MiB / {w[3]}GiB', 'memPerc': '2.1%',
                                            'node': t['node'], 'ncpu': w[2], 'memTotal': int(w[3] * 2**30)}
                else:
                    containers[f"{s['name']}.{t['slot']}.{t['id']}"] = {'swarmStack': st['name'], 'swarmService': s['name'], 'compose': ''}
    base = json.load(open(os.path.join(HERE, 'scenarios', 'api_template.json')))
    base['stacks'] = stacks
    base['containers'] = containers
    base['managed'] = ['paperless', 'smarthome', 'testing'] + (['monitoring'] if nw >= 2 else [])
    base['stackInfo'] = {'paperless': {'path': '/boot/config/plugins/swarm-view/stacks/paperless', 'description': 'Documents'},
                         'smarthome': {'path': '/boot/config/plugins/swarm-view/stacks/smarthome', 'description': 'Home automation'},
                         'testing': {'path': '/boot/config/plugins/swarm-view/stacks/testing', 'description': ''}}
    if nw >= 2:
        base['stackInfo']['monitoring'] = {'path': '/boot/config/plugins/swarm-view/stacks/monitoring', 'description': 'Metrics on every node'}
    base['nodes'] = [{'id': NODE_IDS['Unraid'], 'hostname': 'Unraid', 'self': True, 'role': 'manager', 'availability': 'active', 'state': 'ready',
                      'addr': '192.168.1.10', 'leader': True, 'arch': 'x86_64', 'cpus': 12, 'memGB': 31.2, 'engine': '27.5.1', 'labels': {}}]
    for i, (h, arch, cpus, mem) in enumerate(WORKERS[:nw]):
        base['nodes'].append({'id': NODE_IDS[h], 'hostname': h, 'self': False, 'role': 'worker', 'availability': 'active', 'state': 'ready',
                              'addr': f'192.168.1.{11 + i}', 'leader': False, 'arch': arch, 'cpus': cpus, 'memGB': mem, 'engine': '27.5.1', 'labels': {}})
    base['swarm']['nodeId'] = NODE_IDS['Unraid']
    base['stats']['tasks'] = stats_tasks
    base['stats']['nodes'] = {'Unraid': {'cpu': 18.4, 'mem': 41, 'stale': False, 'ncpu': 12, 'ts': int(NOW.timestamp())}}
    for i, (h, arch, cpus, mem) in enumerate(WORKERS[:nw]):
        base['stats']['nodes'][h] = {'cpu': [37.9, 12.3, 55.0][i], 'mem': [78, 35, 61][i], 'stale': False, 'ncpu': cpus, 'ts': int(NOW.timestamp())}
    base['time'] = int(NOW.timestamp())
    return base, stacks


# Local Docker containers on the fake Unraid (what DockerContainers.php / FolderView read_info see)
local = []


def local_ct(name, short, image, manager='dockerman', compose='', labels=None, ports=(), net='bridge', running=True, autostart=True):
    local.append({'name': name, 'short': short, 'image': image, 'manager': manager, 'compose': compose, 'labels': labels or {},
                  'ports': list(ports), 'net': net, 'running': running, 'autostart': autostart, 'id': tid('u' + name, 12)})


orphans = [{'id': tid('img1', 12), 'tags': 'oldimage:1.0'}, {'id': tid('img2', 12), 'tags': ''},
           {'id': tid('img3', 12), 'tags': '&lt;none&gt;:&lt;none&gt;'}, {'id': tid('img4', 12), 'tags': 'eclipse-mosquitto:2'}]

for nw in (1, 2, 3):
    base, stacks = build(nw)
    local.clear()
    local_ct('mariadb', 'mariadb', 'lscr.io/linuxserver/mariadb:latest', ports=[3306])
    local_ct('nextcloud', 'nextcloud', 'lscr.io/linuxserver/nextcloud:latest', ports=[443])
    local_ct('swag', 'swag', 'lscr.io/linuxserver/swag:latest', ports=[80, 443], net='proxynet')
    local_ct('vaultwarden', 'vaultwarden', 'vaultwarden/server:latest', running=False, autostart=False)
    local_ct('media-jellyfin-1', 'jellyfin', 'jellyfin/jellyfin:latest', manager='composeman', compose='media', ports=[8096],
             labels={'com.docker.compose.project': 'media'})
    for st in stacks:
        for s in st['services']:
            for t in s['tasks']:
                if t['node'] == 'Unraid':
                    local_ct(f"{s['name']}.{t['slot']}.{t['id']}", s['short'], s['image'], manager='', autostart=False,
                             labels={'com.docker.swarm.service.name': s['name'], 'com.docker.stack.namespace': st['name'],
                                     'com.docker.swarm.task.id': t['id']}, ports=[p['target'] for p in s['portList']], net=f"{st['name']}_default")
    local_ct('swarm-view-agent.' + tid('agent', 25)[:25], 'agent', 'alpine:latest', manager='', autostart=False,
             labels={'com.docker.swarm.service.name': 'swarm-view-agent'})
    name = 'world.json' if nw == 1 else f'world-w{nw}.json'
    json.dump({'api': base, 'local': list(local), 'orphans': orphans}, open(os.path.join(HERE, 'scenarios', name), 'w'), indent=1)
    tasks = [t for st in stacks for s in st['services'] for t in s['tasks']]
    print(f'{name}: {nw} worker(s), {len(local)} local containers, {len(tasks)} tasks, '
          + ', '.join(f"{n}={sum(1 for t in tasks if t['node'] == n)}" for n in ['Unraid'] + [w[0] for w in WORKERS[:nw]]))
