#!/usr/bin/env python3
"""Fake `docker` CLI for backend tests. MOCK_MODE selects the cluster state:
manager (default) | inactive | worker | daemon-down | pi-down | empty | garbage | pending | slow | many | commas"""
import json, os, sys, time

mode = os.environ.get('MOCK_MODE', 'manager')
a = sys.argv[1:]
cmd = ' '.join(a[:2])
log = os.environ.get('MOCK_LOG')
if log:
    open(log, 'a').write(' '.join(a) + '\n')

if mode == 'daemon-down':
    print('Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?', file=sys.stderr)
    sys.exit(1)
if mode == 'slow':
    time.sleep(0.4)


def out(x):
    print(x if isinstance(x, str) else json.dumps(x))


NODES = [('n1', 'Unraid', 'manager', 'x86_64', 12), ('n2', 'raspberrypi', 'worker', 'aarch64', 4)]
MULTI = mode.startswith('multi')
DOWN = 'nuc' if mode == 'multi-down' else ('raspberrypi' if mode == 'pi-down' else '')
if MULTI:
    NODES += [('n3', 'nuc', 'worker', 'x86_64', 8), ('n4', 'pi5', 'worker', 'aarch64', 4)]
if mode == 'many':
    NODES += [(f'n{i}', f'node{i}', 'worker', 'aarch64', 4) for i in range(3, 9)]


def services():
    svcs = [('s1', 'smarthome_mosquitto', 'smarthome', 'eclipse-mosquitto:2', [1883], {}),
            ('s2', 'smarthome_homeassistant', 'smarthome', 'ghcr.io/home-assistant/home-assistant:stable', [8123], {'swarm-view.name': 'HomeAssistant'}),
            ('s3', 'paperless_broker', 'paperless', 'redis:7', [], {}),
            ('s4', 'swarm-view-agent', '', 'alpine:latest', [], None)]
    if mode == 'many':
        svcs += [(f's{i}', f'bulk_app{i}', 'bulk', 'traefik/whoami:latest', [], {}) for i in range(10, 70)]
    if mode == 'commas':
        svcs.append(('s9', 'odd_name', 'odd', 'nginx:latest', [80], {'swarm-view.name': 'A, B=C', 'traefik.http.routers.x.rule': 'Host(`a`,`b`)'}))
    if MULTI:
        svcs += [('s20', 'paperless_paperless-ngx', 'paperless', 'ghcr.io/paperless-ngx/paperless-ngx:latest', [8000], {}),
                 ('s21', 'monitoring_node-exporter', 'monitoring', 'prom/node-exporter:latest', [], 'global')]
    if mode == 'empty':
        svcs = []
    return svcs


def task_node(i, name):
    if name == 'smarthome_homeassistant':
        return 'Unraid'
    if MULTI and name == 'paperless_paperless-ngx':
        return 'nuc'
    if MULTI and name == 'paperless_broker':
        return 'pi5'
    if mode == 'many' and name.startswith('bulk_'):
        return f'node{3 + i % 6}'
    return 'raspberrypi'


if cmd == 'info --format':
    state = {'inactive': 'inactive'}.get(mode, 'active')
    out({'NodeID': 'n1' if mode != 'worker' else 'n2', 'LocalNodeState': state, 'ControlAvailable': mode not in ('worker', 'inactive'),
         'Cluster': {'Spec': {'Orchestration': {'TaskHistoryRetentionLimit': 5}}}} if state == 'active' else {'LocalNodeState': 'inactive', 'ControlAvailable': False})
elif cmd == 'node ls':
    for nid, h, role, arch, cpu in NODES:
        out({'ID': nid, 'Hostname': h, 'Status': 'Down' if h == DOWN else 'Ready', 'Availability': 'Active',
             'ManagerStatus': 'Leader' if role == 'manager' else '', 'Self': nid == 'n1'})
elif cmd == 'node inspect':
    if mode == 'garbage':
        out('this is not json'); sys.exit(0)
    res = []
    for nid, h, role, arch, cpu in NODES:
        if nid not in a:
            continue
        res.append({'ID': nid, 'Spec': {'Role': role, 'Availability': 'active', 'Labels': {}},
                    'Description': {'Hostname': h, 'Platform': {'Architecture': arch, 'OS': 'linux'},
                                    'Resources': {'NanoCPUs': cpu * 10**9, 'MemoryBytes': 4 * 2**30}, 'Engine': {'EngineVersion': '27.5.1'}},
                    'Status': {'State': 'down' if h == DOWN else 'ready', 'Addr': '192.168.1.%d' % (10 + int(nid[1:]))},
                    **({'ManagerStatus': {'Leader': True, 'Reachability': 'reachable'}} if role == 'manager' else {})})
    out(res)
elif cmd == 'service ls':
    for sid, name, stack, img, ports, lab in services():
        out({'ID': sid, 'Name': name, 'Mode': 'global' if (lab is None or lab == 'global') else 'replicated',
             'Replicas': (f'{len(NODES)}/{len(NODES)}' if lab == 'global' else '1/1') if mode != 'pending' else '0/1',
             'Image': img, 'Ports': ','.join(f'*:{p}->{p}/tcp' for p in ports)})
elif cmd == 'service inspect':
    res = []
    for sid, name, stack, img, ports, lab in services():
        if sid not in a and name not in a:
            continue
        spec_labels = {'com.docker.stack.namespace': stack} if stack else {'swarm-view.agent': '1'}
        is_global = lab is None or lab == 'global'
        lab = {} if lab == 'global' else lab
        res.append({'ID': sid + 'x' * 20, 'Spec': {'Name': name, 'Labels': spec_labels,
                    'TaskTemplate': {'ContainerSpec': {'Image': img + '@sha256:' + 'a' * 64, 'Labels': lab or {},
                                                       'Mounts': [{'Type': 'bind', 'Source': f'/mnt/user/appdata/{name}', 'Target': '/config'}]},
                                     'Placement': {'Constraints': ['node.role == manager'] if name == 'smarthome_homeassistant' else []}},
                    'Mode': {'Global': {}} if is_global else {'Replicated': {'Replicas': 1}}},
                    'Endpoint': {'Ports': [{'TargetPort': p, 'PublishedPort': p, 'Protocol': 'tcp'} for p in ports]}})
    out(res)
elif cmd == 'service ps':
    for i, (sid, name, stack, img, ports, lab) in enumerate(services()):
        if sid not in a and name not in a:
            continue
        if lab == 'global':
            for nid, h, *_ in NODES:
                out({'ID': f'g{nid}{i:021d}', 'Name': f'{name}.{nid}xxxxxxxxxxxxxxxxxxxxxxx', 'Node': h,
                     'CurrentState': 'Running 1 hour ago' if h != DOWN else 'Running 3 hours ago', 'Error': ''})
            continue
        node = task_node(i, name)
        if mode == 'pending' and name == 'paperless_broker':
            out({'ID': f't{i:024d}', 'Name': f'{name}.1', 'Node': '', 'CurrentState': 'Pending 1 minute ago', 'Error': 'no suitable node (scheduling constraints not satisfied on 2 nodes)'})
            continue
        out({'ID': f't{i:024d}', 'Name': f'{name}.1', 'Node': node, 'CurrentState': 'Running 2 hours ago', 'Error': ''})
elif cmd == 'inspect --type':
    res = []
    for tid in a[3:]:
        res.append({'ID': tid, 'CreatedAt': '2026-11-20T10:00:00Z', 'Status': {'Timestamp': '2026-11-21T10:00:00Z',
                    'ContainerStatus': {'ContainerID': 'c' * 64}},
                    'NetworksAttachments': [{'Network': {'Spec': {'Name': 'smarthome_default'}}, 'Addresses': ['10.0.3.5/24']}]})
    out(res)
elif cmd == 'ps -a':
    out({'Names': 'mariadb', 'Labels': 'net.unraid.docker.managed=dockerman'})
    out({'Names': 'smarthome_homeassistant.1.t' + '0' * 23 + '1', 'Labels': 'com.docker.stack.namespace=smarthome,com.docker.swarm.service.name=smarthome_homeassistant,com.docker.swarm.task.id=t1'})
    if mode == 'commas':
        out({'Names': 'weird', 'Labels': 'traefik.http.routers.x.rule=Host(`a`,`b`),com.docker.compose.project=media'})
elif cmd == 'service logs':
    if DOWN:
        print(f'error from daemon in stream: Error grabbing logs: rpc error: node {DOWN} is down', file=sys.stderr)
    ts = int(time.time())
    out(json.dumps({'node': 'Unraid', 'ts': ts, 'cpu': 184, 'ncpu': 12, 'memTotal': 32 * 2**20, 'memAvail': 18 * 2**20,
                    'stats': [{'Name': 'smarthome_homeassistant.1.t' + '0' * 23 + '1', 'CPUPerc': '1.20%', 'MemUsage': '155MiB / 31.2GiB', 'MemPerc': '0.5%'}]}))
    for nid, h, role, arch, cpu in NODES[1:]:
        if h == DOWN:
            continue
        st = [{'Name': 'smarthome_mosquitto.1.t' + '0' * 24, 'CPUPerc': '0.05%', 'MemUsage': '0B / 0B', 'MemPerc': '0.00%'}] if h == 'raspberrypi' else []
        if h == 'nuc' and MULTI:
            st.append({'Name': 'paperless_paperless-ngx.1.t' + '0' * 22 + '19', 'CPUPerc': '3.00%', 'MemUsage': '412MiB / 15.5GiB', 'MemPerc': '2.6%'})
        out(json.dumps({'node': h, 'ts': ts, 'cpu': 379, 'ncpu': cpu, 'memTotal': 3800000, 'memAvail': 900000, 'stats': st,
                        'rss': {'smarthome_mosquitto.1.t' + '0' * 24: 9216} if h == 'raspberrypi' else {}}))
    if DOWN:
        sys.exit(1)
elif cmd in ('service create', 'service update', 'service rm', 'node update', 'swarm update', 'stack deploy', 'stack rm', 'image prune', 'container prune'):
    out('ok')
elif cmd == 'stack ls':
    out('')
else:
    print(f'mock: unsupported: {" ".join(a)}', file=sys.stderr)
    sys.exit(1)
