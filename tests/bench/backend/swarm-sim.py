#!/usr/bin/env python3
"""Stateful fake `docker` CLI for the watchdog tests: a tiny swarm in a JSON file ($SIM_WORLD).

World: {"nodes": {host: {"ready": bool, "avail": "active|pause|drain", "arch": "x86_64", "role": "manager|worker"}},
        "services": {name: {"labels": {}, "constraints": [], "global": false, "image": "…", "node": host|"", "state": "running|starting|pending",
                            "error": "", "failOn": [hosts where the container does not start], "task": n, "updated": ts}},
        "seq": n}
Every call first reconciles like Swarm: a task on a node that is down or not eligible moves to an eligible node;
on a node from failOn the task stays "Starting" with an error (not running). Time: SV_NOW."""
import json, os, re, sys, time

W = os.environ['SIM_WORLD']
NOW = int(os.environ.get('SV_NOW') or time.time())
a = sys.argv[1:]
log = os.environ.get('MOCK_LOG')
if log:
    open(log, 'a').write(' '.join(a) + '\n')
world = json.load(open(W))
nodes, svcs = world['nodes'], world['services']


def save():
    json.dump(world, open(W + '.tmp', 'w'), indent=1)
    os.replace(W + '.tmp', W)


def iso(ts):
    return time.strftime('%Y-%m-%dT%H:%M:%S.000000000Z', time.gmtime(ts))


def fits(host, cons):
    n = nodes[host]
    for c in cons:
        m = re.match(r'\s*node\.(hostname|role)\s*(==|!=)\s*(\S+)\s*$', c)
        if not m:
            continue
        val = host if m.group(1) == 'hostname' else n.get('role', 'worker')
        if (val == m.group(3)) != (m.group(2) == '=='):
            return False
    return True


def eligible(s):
    return [h for h in nodes if nodes[h]['ready'] and nodes[h]['avail'] == 'active' and fits(h, s['constraints'])]


def place(s, force=False):
    """Swarm scheduling for one replicated service: keep the task while its node is eligible and it runs."""
    el = eligible(s)
    cur = s.get('node', '')
    if not force and cur in el and s['state'] == 'running':
        return
    if not force and cur in el and s['state'] == 'starting' and len(el) == 1:
        return                                     # keeps retrying on the only possible node
    good = [h for h in el if h not in s.get('failOn', [])]
    world['seq'] = world.get('seq', 100) + 1
    s['task'] = world['seq']
    if good:
        s.update(node=(cur if cur in good else good[0]), state='running', error='')
    elif el:
        s.update(node=el[0], state='starting', error='starting container failed: port is already allocated')
    else:
        s.update(node='', state='pending', error='no suitable node (scheduling constraints not satisfied)')


for s in svcs.values():
    if not s.get('global'):
        place(s)
save()

cmd = ' '.join(a[:2])
if a[:1] == ['info']:
    print(json.dumps({'NodeID': 'Unraid', 'LocalNodeState': 'active', 'ControlAvailable': True, 'Cluster': {'Spec': {}}}))
elif cmd == 'node ls' and '-q' in a:
    print('\n'.join(nodes))
elif cmd == 'node ls':
    for h, n in nodes.items():
        print(json.dumps({'ID': h, 'Hostname': h, 'Status': 'Ready' if n['ready'] else 'Down', 'Availability': n['avail'].capitalize(), 'Self': h == 'Unraid'}))
elif cmd == 'node inspect':
    print(json.dumps([{'ID': h, 'Description': {'Hostname': h, 'Platform': {'Architecture': nodes[h].get('arch', 'x86_64'), 'OS': 'linux'}},
                       'Spec': {'Role': nodes[h].get('role', 'worker'), 'Availability': nodes[h]['avail']},
                       'Status': {'State': 'ready' if nodes[h]['ready'] else 'down'}} for h in a[2:] if h in nodes]))
elif cmd == 'service ls' and '-q' in a:
    print('\n'.join(svcs))
elif cmd == 'service ls':
    for n, s in svcs.items():
        print(json.dumps({'ID': n, 'Name': n, 'Mode': 'global' if s.get('global') else 'replicated',
                          'Replicas': '1/1' if s['state'] == 'running' else '0/1', 'Image': s.get('image', 'nginx:latest')}))
elif a[:1] == ['ps']:
    pass
elif cmd == 'service inspect':
    names = [x for x in a[2:] if not x.startswith('-') and '{{' not in x and x in svcs]

    def obj(n):
        lab = dict(svcs[n].get('labels', {}))
        if '_' in n:
            lab.setdefault('com.docker.stack.namespace', n.split('_', 1)[0])
        return {'ID': n, 'UpdatedAt': iso(svcs[n].get('updated', 0)),
                'Spec': {'Name': n, 'Labels': lab,
                         'Mode': {'Global': {}} if svcs[n].get('global') else {'Replicated': {'Replicas': 1}},
                         'TaskTemplate': {'ContainerSpec': {'Image': svcs[n].get('image', 'nginx:latest@sha256:' + '1' * 64), 'Labels': {}},
                                          'Placement': {'Constraints': svcs[n]['constraints']}}}}
    if not names:
        print('[]' if '--format' not in a else ''); print('Error: no such service', file=sys.stderr); sys.exit(1)
    if '--format' in a:
        f = a[a.index('--format') + 1]
        for n in names:
            print(json.dumps(svcs[n]['constraints']) if 'Constraints' in f else json.dumps(obj(n)))
        sys.exit(0)
    print(json.dumps([obj(n) for n in names]))
elif cmd == 'service ps':
    for n in [x for x in a[2:] if x in svcs]:
        s = svcs[n]
        st = {'running': 'Running 5 minutes ago', 'starting': 'Starting 10 seconds ago', 'pending': 'Pending 1 minute ago'}[s['state']]
        print(json.dumps({'ID': f"task{s.get('task', 0)}", 'Name': f'{n}.1', 'Node': s.get('node', ''), 'CurrentState': st, 'Error': s.get('error', ''), 'DesiredState': 'Running'}))
elif cmd == 'service update':
    name = a[-1]
    if name not in svcs:
        print(f'Error: service {name} not found', file=sys.stderr); sys.exit(1)
    s = svcs[name]
    i, force = 2, False
    while i < len(a) - 1:
        o = a[i]
        if o == '--constraint-add':
            s['constraints'].append(a[i + 1]); i += 2
        elif o == '--constraint-rm':
            s['constraints'] = [c for c in s['constraints'] if re.sub(r'\s', '', c) != re.sub(r'\s', '', a[i + 1])]; i += 2
        elif o == '--label-add':
            k, v = a[i + 1].split('=', 1); s.setdefault('labels', {})[k] = v; i += 2
        elif o == '--label-rm':
            s.get('labels', {}).pop(a[i + 1], None); i += 2
        elif o == '--force':
            force = True; i += 1
        else:
            i += 1
    s['updated'] = NOW
    place(s, force)
    save()
    print(name)
elif cmd in ('stack config', 'stack deploy'):
    print('ok')
elif cmd == 'stack ls':
    print('')
else:
    print(f'swarm-sim: unsupported: {" ".join(a)}', file=sys.stderr)
    sys.exit(1)
