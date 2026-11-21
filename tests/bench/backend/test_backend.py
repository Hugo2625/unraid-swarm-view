#!/usr/bin/env python3
"""Backend tests: runs Swarm View's api.php (and a few actions) with a fake docker CLI in different cluster states.
Usage: python3 backend/test_backend.py   (needs php-cli; Swarm View source via SV_SRC or ../unraid-swarm-view)"""
import json, os, subprocess, sys, tempfile, time

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.environ.get('SV_SRC') or next((p for p in (os.path.join(HERE, '..', '..', '..', 'src', 'swarm-view'),
                                                     os.path.join(HERE, '..', '..', 'unraid-swarm-view', 'src', 'swarm-view')) if os.path.isdir(p)), '')
SRC = os.path.abspath(SRC)
MOCK = os.path.join(HERE, 'docker-mock.py')
results = []


def run_php(code_or_file, mode, cfg='', post=None, is_file=True):
    tmp = tempfile.mkdtemp(prefix='svbe-')
    open(f'{tmp}/swarm-view.cfg', 'w').write(cfg or 'STATS="yes"\n')
    os.makedirs(f'{tmp}/state', exist_ok=True)
    os.makedirs(f'{tmp}/stacks', exist_ok=True)
    env = dict(os.environ, SV_DOCKER=MOCK, MOCK_MODE=mode, MOCK_LOG=f'{tmp}/docker.log', SV_CFG=f'{tmp}/swarm-view.cfg',
               SV_STATE=f'{tmp}/state', SV_STACK_ROOT=f'{tmp}/stacks', SV_NOTIFY='/bin/true', SV_DYNAMIX_CFG=f'{tmp}/dynamix.cfg')
    if post is not None:
        prelude = ("$_SERVER['REQUEST_METHOD']='POST'; $_POST=json_decode(getenv('SVTB_POST'), true); "
                   f"chdir('{SRC}/include'); include '{SRC}/include/actions.php';")
        env['SVTB_POST'] = json.dumps(post)
        cmd = ['php', '-d', 'short_open_tag=1', '-d', 'display_errors=1', '-r', prelude]
    else:
        cmd = ['php', '-d', 'short_open_tag=1', '-d', 'display_errors=1', code_or_file]
    t = time.time()
    p = subprocess.run(cmd, env=env, capture_output=True, text=True, timeout=60)
    return p.stdout, p.stderr, round(time.time() - t, 2), open(f'{tmp}/docker.log').read() if os.path.exists(f'{tmp}/docker.log') else ''


def check(name, ok, info=''):
    results.append((name, bool(ok), info))
    print(('OK  ' if ok else 'FAIL') + f' {name}' + (f'  — {info}' if info and not ok else ''))


API = os.path.join(SRC, 'include', 'api.php')
for mode in ['manager', 'inactive', 'worker', 'daemon-down', 'pi-down', 'empty', 'garbage', 'pending', 'slow', 'many', 'commas', 'multi', 'multi-down']:
    out, err, secs, dlog = run_php(API, mode)
    try:
        d = json.loads(out)
    except Exception as e:
        check(f'api.{mode}.valid-json', False, f'{e}: {out[:200]!r}')
        continue
    check(f'api.{mode}.valid-json', True)
    check(f'api.{mode}.no-php-notices', 'Warning' not in out + err and 'Notice' not in out + err and 'Deprecated' not in out + err, (err or '')[:200])
    for k in ('ok', 'host', 'swarm', 'nodes', 'stacks', 'containers', 'stats', 'config', 'errors'):
        if k not in d:
            check(f'api.{mode}.has-{k}', False, 'missing')
    check(f'api.{mode}.containers-is-object', isinstance(d.get('containers'), dict), type(d.get('containers')).__name__)
    if mode == 'manager':
        names = [s['name'] for st in d['stacks'] for s in st['services']]
        check('api.manager.agent-hidden', 'swarm-view-agent' not in names, names)
        ha = next(s for st in d['stacks'] for s in st['services'] if s['short'] == 'homeassistant')
        check('api.manager.alias', ha['alias'] == 'HomeAssistant', ha['alias'])
        check('api.manager.local-task', ha['tasks'][0]['local'] is True, ha['tasks'][0])
        mq = next(s for st in d['stacks'] for s in st['services'] if s['short'] == 'mosquitto')
        check('api.manager.remote-details', mq['tasks'][0].get('containerId') and mq['tasks'][0].get('networks'), mq['tasks'][0])
        check('api.manager.stats-rss-fallback', any(v.get('memApprox') for v in d['stats']['tasks'].values()), d['stats']['tasks'])
        check('api.manager.image-digest-stripped', '@sha256' not in mq['image'], mq['image'])
    if mode in ('inactive', 'worker'):
        check(f'api.{mode}.no-stacks', d['stacks'] == [] and d['swarm']['active'] == (mode == 'worker'), d['swarm'])
    if mode == 'daemon-down':
        check('api.daemon-down.reports-error', d['ok'] is False and d['errors'], d['errors'])
    if mode == 'pi-down':
        pi = next((n for n in d['nodes'] if n['hostname'] == 'raspberrypi'), {})
        check('api.pi-down.node-state', pi.get('state') == 'down', pi)
        check('api.pi-down.unraid-stats-kept', 'Unraid' in d['stats']['nodes'], list(d['stats']['nodes']))
    if mode == 'garbage':
        check('api.garbage.no-crash', isinstance(d['nodes'], list), d['nodes'])
    if mode == 'pending':
        br = next((s for st in d['stacks'] for s in st['services'] if s['short'] == 'broker'), None)
        check('api.pending.task-error-kept', br and br['tasks'] and 'no suitable node' in br['tasks'][0]['error'], br and br['tasks'])
        check('api.pending.replicas', br and br['replicas'].startswith('0/'), br and br['replicas'])
    if mode == 'many':
        check('api.many.all-services', sum(len(st['services']) for st in d['stacks']) >= 62, sum(len(st['services']) for st in d['stacks']))
        check('api.many.inspect-chunked', dlog.count('inspect --type task') >= 2, dlog.count('inspect --type task'))
    if mode == 'commas':
        w = d['containers'].get('weird', {})
        check('api.commas.label-parsing', w.get('compose') == 'media', w)
        odd = next((s for st in d['stacks'] for s in st['services'] if s['short'] == 'odd_name' or s['name'] == 'odd_name'), None)
        check('api.commas.alias-with-commas', odd and odd['alias'] == 'A, B=C', odd and odd['alias'])
    if mode.startswith('multi'):
        hosts = [n['hostname'] for n in d['nodes']]
        check(f'api.{mode}.four-nodes', hosts[0] == 'Unraid' and sorted(hosts[1:]) == ['nuc', 'pi5', 'raspberrypi'], hosts)
        g = next((s for st in d['stacks'] for s in st['services'] if s['name'] == 'monitoring_node-exporter'), None)
        check(f'api.{mode}.global-service', g and g['mode'] == 'global' and len(g['tasks']) == 4 and sum(t['local'] for t in g['tasks']) == 1,
              g and [(t['node'], t['slot'], t['local']) for t in g['tasks']])
        check(f'api.{mode}.global-slots-unique', g and len({t['slot'] for t in g['tasks']}) == 4, g and [t['slot'] for t in g['tasks']])
        pp = next((s for st in d['stacks'] for s in st['services'] if s['name'] == 'paperless_paperless-ngx'), None)
        check(f'api.{mode}.task-on-nuc', pp and pp['tasks'][0]['node'] == 'nuc' and not pp['tasks'][0]['local'], pp and pp['tasks'])
        sn = d['stats']['nodes']
        if mode == 'multi':
            check('api.multi.stats-all-nodes', set(sn) == {'Unraid', 'raspberrypi', 'nuc', 'pi5'}, list(sn))
            check('api.multi.stats-task-on-nuc', any(v['node'] == 'nuc' for v in d['stats']['tasks'].values()), d['stats']['tasks'])
        else:
            check('api.multi-down.node-down', next(n for n in d['nodes'] if n['hostname'] == 'nuc')['state'] == 'down', d['nodes'])
            check('api.multi-down.others-kept', {'Unraid', 'raspberrypi', 'pi5'} <= set(sn), list(sn))
    check(f'api.{mode}.runtime', secs < (10 if mode == 'slow' else 5), f'{secs}s')

# actions in degraded states must answer with JSON and ok:false instead of PHP errors
for mode, post in [('daemon-down', {'action': 'place', 'service': 'smarthome_mosquitto', 'mode': 'fixed', 'node': 'raspberrypi'}),
                   ('inactive', {'action': 'place', 'service': 'smarthome_mosquitto', 'mode': 'prefer', 'node': 'raspberrypi'}),
                   ('manager', {'action': 'place', 'service': 'does_not_exist', 'mode': 'prefer', 'node': 'raspberrypi'}),
                   ('manager', {'action': 'place', 'service': 'smarthome_mosquitto', 'mode': 'prefer', 'node': 'nonexistent-node'}),
                   ('manager', {'action': 'place', 'service': 'smarthome_mosquitto; rm -rf /', 'mode': 'fixed', 'node': 'x'}),
                   ('manager', {'action': 'settings', 'SWARM_POSITION': 'middle', 'LANGUAGE': 'xx', 'ORPHAN_IMAGES': '<script>'}),
                   ('manager', {'action': 'nonsense'}),
                   ('daemon-down', {'action': 'logs', 'task': 'abcdefghij0123456789abcdef', 'tail': '50'})]:
    out, err, secs, dlog = run_php(None, mode, post=post)
    label = f"action.{post['action']}.{mode}"
    try:
        d = json.loads(out[out.index('{'):]) if '{' in out else None
    except Exception:
        d = None
    check(f'{label}.json', d is not None and out.lstrip().startswith('{'), (out or err)[:200])
    if d is not None:
        if post.get('service', '').startswith('smarthome_mosquitto;'):
            check(f'{label}.rejects-injection', d.get('ok') is False and 'rm -rf' not in dlog, dlog[-200:])
        if post['action'] == 'settings':
            cfg = open(f"{os.path.dirname(dlog and '') or ''}" or '/dev/null').read() if False else ''
            check(f'{label}.ok', d.get('ok') is not False, d)
        if mode in ('daemon-down', 'inactive') or post.get('service') == 'does_not_exist' or post['action'] == 'nonsense':
            check(f'{label}.not-ok', d.get('ok') is False, d)

fails = [r for r in results if not r[1]]
print(f'\n{len(results)} backend checks, {len(fails)} failed')
sys.exit(1 if fails else 0)
