#!/usr/bin/env python3
"""Watchdog tests: runs Swarm View's monitor (return to the preferred node, failover, log) against a simulated swarm
(swarm-sim.py) over simulated time, plus the "returning" state in api.php and the log.
Usage: python3 tests/bench/backend/test_monitor.py   (needs php-cli)"""
import json, os, subprocess, sys, tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.abspath(os.environ.get('SV_SRC') or os.path.join(HERE, '..', '..', '..', 'src', 'swarm-view'))
SIM = os.path.join(HERE, 'swarm-sim.py')
T0 = 1_800_000_000
results = []


def check(name, ok, info=''):
    results.append((name, bool(ok)))
    print(('OK  ' if ok else 'FAIL') + f' {name}' + (f'  — {info}' if info and not ok else ''))


class Swarm:
    def __init__(self, services, cfg='REBALANCE="yes"\nREBALANCE_DELAY="5"\n', nodes=None):
        self.dir = tempfile.mkdtemp(prefix='svmon-')
        self.world = f'{self.dir}/world.json'
        nodes = nodes or {'Unraid': {'ready': True, 'avail': 'active', 'role': 'manager'},
                          'raspberrypi': {'ready': True, 'avail': 'active', 'arch': 'aarch64'}}
        svcs = {}
        for name, s in services.items():
            svcs[name] = dict({'labels': {}, 'constraints': [], 'node': '', 'state': 'pending', 'failOn': [], 'updated': T0 - 3600}, **s)
        json.dump({'nodes': nodes, 'services': svcs, 'seq': 100}, open(self.world, 'w'))
        open(f'{self.dir}/swarm-view.cfg', 'w').write(cfg)
        open(f'{self.dir}/registry.json', 'w').write('{}')
        os.makedirs(f'{self.dir}/state', exist_ok=True)
        os.makedirs(f'{self.dir}/stacks', exist_ok=True)
        self.now = T0

    def env(self):
        return dict(os.environ, SV_DOCKER=SIM, SIM_WORLD=self.world, SV_NOW=str(self.now), MOCK_LOG=f'{self.dir}/docker.log',
                    SV_CFG=f'{self.dir}/swarm-view.cfg', SV_STATE=f'{self.dir}/state', SV_STACK_ROOT=f'{self.dir}/stacks',
                    SV_NOTIFY='/bin/true', SV_DYNAMIX_CFG=f'{self.dir}/dynamix.cfg', SV_FAKE_REGISTRY=f'{self.dir}/registry.json')

    def php(self, code, post=None):
        env = self.env()
        if post is not None:
            env['SVTB_POST'] = json.dumps(post)
            code = ("$_SERVER['REQUEST_METHOD']='POST'; $_POST=json_decode(getenv('SVTB_POST'), true); "
                    f"chdir('{SRC}/include'); include '{SRC}/include/actions.php';")
        p = subprocess.run(['php', '-d', 'short_open_tag=1', '-d', 'display_errors=1', '-r', code], env=env, capture_output=True, text=True, timeout=60)
        if 'Fatal error' in p.stdout + p.stderr or 'Warning:' in p.stdout + p.stderr:
            print(p.stdout[-800:], p.stderr[-800:])
        return p.stdout

    def run(self, minutes=0):
        self.now += int(minutes * 60)
        out = self.php(f"require '{SRC}/include/monitor.php'; echo json_encode(sv_monitor_run(sv_cfg()));")
        try:
            return json.loads(out[out.index('['):])
        except Exception:
            print('monitor output:', out[-500:])
            return []

    def api(self):
        out = self.php(f"chdir('{SRC}/include'); include '{SRC}/include/api.php';")
        try:
            d = json.loads(out[out.index('{'):])
        except Exception:
            print('api output:', out[-500:])
            return {}
        return {s['name']: s for st in d.get('stacks', []) for s in st.get('services', [])}

    def svc(self, name):
        return json.load(open(self.world))['services'][name]

    def state(self):
        f = f'{self.dir}/state/monitor.json'
        return json.load(open(f)) if os.path.exists(f) else {}

    def set_state(self, st):
        json.dump(st, open(f'{self.dir}/state/monitor.json', 'w'))

    def edit(self, fn):
        w = json.load(open(self.world)); fn(w); json.dump(w, open(self.world, 'w'))

    def logs(self):
        f = f'{self.dir}/state/swarm-view.log'
        return [json.loads(l) for l in open(f)] if os.path.exists(f) else []

    def docker(self):
        f = f'{self.dir}/docker.log'
        return open(f).read() if os.path.exists(f) else ''


PREF = {'swarm-view.prefer': 'raspberrypi'}

# ---------- 1. successful return ----------
sw = Swarm({'app_web': {'labels': dict(PREF), 'node': 'Unraid', 'state': 'running'}})
sw.run()                                                    # readySince of raspberrypi = T0
check('return.waits-for-stable-node', 'constraint-add' not in sw.docker() and not sw.state().get('rebalance'))
sw.run(6)
st = sw.state()
check('return.starts-after-delay', 'app_web' in st.get('rebalance', {}) and 'node.hostname==raspberrypi' in sw.svc('app_web')['constraints'], st.get('rebalance'))
check('return.service-moved', sw.svc('app_web')['node'] == 'raspberrypi' and sw.svc('app_web')['state'] == 'running')
a = sw.api().get('app_web', {})
check('api.arrived-no-badge', a.get('rebalancing') is False and a.get('placeNode') == '' and a.get('prefer') == 'raspberrypi', {k: a.get(k) for k in ('rebalancing', 'placeNode', 'prefer')})
sw.run(1)
check('return.finishes', not sw.state().get('rebalance') and sw.svc('app_web')['constraints'] == [], (sw.state().get('rebalance'), sw.svc('app_web')['constraints']))
check('return.logged', any(e['m'] == 'app_web is back on raspberrypi.' and e['l'] == 'info' for e in sw.logs()), [e['m'] for e in sw.logs()])
check('log.no-debug-at-info', not any(e['l'] == 'debug' for e in sw.logs()))
sw.run(1)
check('return.no-second-move', sw.docker().count('constraint-add') == 1)

# ---------- 2. return that fails: cancel, back off, retry later ----------
sw = Swarm({'audnexus_api': {'labels': dict(PREF), 'node': 'Unraid', 'state': 'running', 'failOn': ['raspberrypi']}})
sw.run()
sw.run(6)
check('fail.return-started', 'audnexus_api' in sw.state().get('rebalance', {}))
check('fail.service-down-on-target', sw.svc('audnexus_api')['state'] == 'starting')
a = sw.api().get('audnexus_api', {})
check('api.badge-while-returning', a.get('rebalancing') is True and a.get('placeNode') == '', {k: a.get(k) for k in ('rebalancing', 'placeNode')})
sw.run(2)
check('fail.still-waiting-at-2min', 'audnexus_api' in sw.state().get('rebalance', {}))
sw.run(4)                                                    # 6 min after the start: down for > 5 min → cancel
st = sw.state()
check('fail.cancelled-when-down', not st.get('rebalance') and sw.svc('audnexus_api')['constraints'] == [], (st.get('rebalance'), sw.svc('audnexus_api')['constraints']))
check('fail.runs-elsewhere-again', sw.svc('audnexus_api')['state'] == 'running' and sw.svc('audnexus_api')['node'] == 'Unraid', sw.svc('audnexus_api'))
check('fail.backoff-stored', st.get('rebalanceFail', {}).get('audnexus_api', {}).get('n') == 1)
check('fail.logged-warning', any(e['l'] == 'warn' and 'does not start on raspberrypi' in e['m'] and 'Next attempt in 30 minutes' in e['m'] and 'port is already allocated' in e.get('d', '') for e in sw.logs()), [e['m'] for e in sw.logs()])
a = sw.api().get('audnexus_api', {})
check('api.no-badge-after-cancel', a.get('rebalancing') is False and a.get('placeNode') == '', {k: a.get(k) for k in ('rebalancing', 'placeNode')})
n_add = sw.docker().count('constraint-add')
for _ in range(5):
    sw.run(5)                                                # 25 min: still within the 30 min backoff
check('fail.no-retry-during-backoff', sw.docker().count('constraint-add') == n_add, sw.docker().count('constraint-add'))
sw.run(6)                                                    # > 30 min → second attempt
check('fail.retries-after-backoff', sw.docker().count('constraint-add') == n_add + 1)
sw.run(3); sw.run(3)
check('fail.backoff-doubles', sw.state().get('rebalanceFail', {}).get('audnexus_api', {}).get('n') == 2 and any('Next attempt in 60 minutes' in e['m'] for e in sw.logs()))

# ---------- 3. stale entries (the bug from the screenshots) ----------
sw = Swarm({'media_lidarr': {'node': 'Unraid', 'state': 'running', 'constraints': ['node.role == manager']},
            'smarthome_esphome': {'node': 'Unraid', 'state': 'running'},
            'smarthome_nodered': {'labels': dict(PREF), 'node': 'raspberrypi', 'state': 'running'}})
sw.run()
st = sw.state()
st['rebalance'] = {'media_lidarr': {'since': T0 - 7200, 'node': 'Unraid'}, 'smarthome_esphome': {'since': T0 - 60, 'node': 'Unraid'},
                   'smarthome_nodered': {'since': T0 - 30, 'node': 'raspberrypi'}, 'gone_service': {'since': T0, 'node': 'Unraid'}}
sw.set_state(st)
api = sw.api()
check('stale.api-no-badge', not any(api.get(n, {}).get('rebalancing') for n in ('media_lidarr', 'smarthome_esphome', 'smarthome_nodered')),
      {n: api.get(n, {}).get('rebalancing') for n in api})
check('stale.api-file-constraint-not-hidden', api.get('media_lidarr', {}).get('placeNode') == '' and api.get('media_lidarr', {}).get('constraints') == ['node.role == manager'])
sw.run(1)
check('stale.monitor-clears-all', not sw.state().get('rebalance'), sw.state().get('rebalance'))
check('stale.no-false-back-on-message', not any('back on' in e['m'] for e in sw.logs()), [e['m'] for e in sw.logs()])
check('log.no-join-message-on-first-run', not any('joined the swarm' in e['m'] for e in sw.logs()))
check('stale.no-constraints-touched', sw.svc('media_lidarr')['constraints'] == ['node.role == manager'] and 'constraint-rm' not in sw.docker())

# overdue entry whose temporary binding is still there (watchdog did not run): shown as what it is – a fixed node
sw = Swarm({'app_x': {'node': 'raspberrypi', 'state': 'running', 'constraints': ['node.hostname==raspberrypi']}}, cfg='REBALANCE="no"\n')
sw.set_state({'rebalance': {'app_x': {'since': T0 - 3600, 'node': 'raspberrypi'}}})
a = sw.api().get('app_x', {})
check('stale.overdue-shows-fixed-node', a.get('rebalancing') is False and a.get('placeNode') == 'raspberrypi', {k: a.get(k) for k in ('rebalancing', 'placeNode')})

# ---------- 4. switching the automatic return off ends pending returns at once ----------
sw = Swarm({'app_web': {'labels': dict(PREF), 'node': 'Unraid', 'state': 'running', 'failOn': ['raspberrypi']}})
sw.run(); sw.run(6)
check('off.return-running', 'app_web' in sw.state().get('rebalance', {}))
out = sw.php('', post={'action': 'settings', 'REBALANCE': 'no'})
check('off.settings-ok', '"ok":true' in out, out[-300:])
check('off.constraint-removed', sw.svc('app_web')['constraints'] == [] and not sw.state().get('rebalance'), (sw.svc('app_web')['constraints'], sw.state().get('rebalance')))
check('off.logged', any('Pending returns stopped: app_web' in e['m'] for e in sw.logs()) and any(e['s'] == 'settings' and 'REBALANCE: yes → no' in e.get('d', '') for e in sw.logs()))

# ---------- 5. Stack Up voids returns of that stack ----------
sw = Swarm({'shop_api': {'labels': dict(PREF), 'node': 'Unraid', 'state': 'running', 'failOn': ['raspberrypi']},
            'other_db': {'labels': dict(PREF), 'node': 'Unraid', 'state': 'running', 'failOn': ['raspberrypi']}})
sw.run(); sw.run(6)
os.makedirs(f'{sw.dir}/stacks/shop'); open(f'{sw.dir}/stacks/shop/docker-stack.yml', 'w').write('services: {}\n')
out = sw.php('', post={'action': 'deploy', 'name': 'shop'})
check('deploy.ok', '"ok":true' in out, out[-300:])
check('deploy.forgets-own-returns', set(sw.state().get('rebalance', {})) == {'other_db'}, sw.state().get('rebalance'))
check('deploy.logged-action', any(e['s'] == 'action' and e['m'] == 'Stack Up shop' and e['l'] == 'info' for e in sw.logs()))

# ---------- 6. preferred node changed during a return ----------
sw = Swarm({'app_web': {'labels': dict(PREF), 'node': 'Unraid', 'state': 'running', 'failOn': ['raspberrypi']}},
           nodes={'Unraid': {'ready': True, 'avail': 'active', 'role': 'manager'}, 'raspberrypi': {'ready': True, 'avail': 'active'}, 'nuc': {'ready': True, 'avail': 'active'}})
sw.run(); sw.run(6)
sw.edit(lambda w: w['services']['app_web']['labels'].update({'swarm-view.prefer': 'nuc'}))
sw.run(1)
check('changed.old-binding-removed', 'node.hostname==raspberrypi' not in sw.svc('app_web')['constraints'], sw.svc('app_web')['constraints'])
check('changed.logged', any('stopped – the placement was changed' in e['m'] for e in sw.logs()))

# ---------- 7. node outage: failover and node state in the log ----------
sw = Swarm({'app_web': {'labels': dict(PREF), 'node': 'raspberrypi', 'state': 'running'}})
sw.run()
sw.edit(lambda w: w['nodes']['raspberrypi'].update(ready=False))
sw.run(1)
check('outage.failover', sw.svc('app_web')['node'] == 'Unraid')
check('outage.node-offline-logged', any(e['l'] == 'warn' and e['m'] == 'Node raspberrypi is offline (before: online).' for e in sw.logs()), [e['m'] for e in sw.logs()])
check('outage.move-logged', any(e['m'] == 'app_web now runs on Unraid (Before: raspberrypi)' for e in sw.logs()), [e['m'] for e in sw.logs()])
sw.edit(lambda w: w['nodes']['raspberrypi'].update(ready=True))
sw.run(1)
check('outage.node-online-logged', any(e['m'] == 'Node raspberrypi is online (before: offline).' for e in sw.logs()))
sw.run(3)
check('outage.no-return-before-delay', not sw.state().get('rebalance'))
sw.run(3)
check('outage.returns-after-delay', sw.svc('app_web')['node'] == 'raspberrypi')

# ---------- 8. log: debug level, reading, clearing, size limit ----------
sw = Swarm({'app_web': {'labels': dict(PREF), 'node': 'Unraid', 'state': 'running', 'constraints': ['node.hostname==Unraid']}},
           cfg='REBALANCE="yes"\nLOG_LEVEL="debug"\n')
sw.run(); sw.run(6)
dbg = [e['m'] for e in sw.logs() if e['l'] == 'debug']
check('log.debug-run-summary', any(m.startswith('Watchdog run: 2 nodes, 1 services') for m in dbg), dbg)
check('log.debug-explains-no-return', any('fixed node in the constraints' in m for m in dbg), dbg)
out = sw.php(f"$_GET=['min'=>'warn']; include '{SRC}/include/log.php';")
d = json.loads(out)
check('log.endpoint', d.get('ok') is True and d.get('level') == 'debug' and all(e['l'] in ('warn', 'error') for e in d['entries']), out[:200])
out = sw.php('', post={'action': 'log-clear'})
check('log.clear', '"ok":true' in out and [e['m'] for e in sw.logs()] == ['Log cleared.'], [e['m'] for e in sw.logs()])
sw.php(f"require_once '{SRC}/include/common.php'; " + "for ($i = 0; $i < 9000; $i++) sv_log('info', 'action', 'entry ' . $i, str_repeat('x', 100));")
size = os.path.getsize(f'{sw.dir}/state/swarm-view.log')
lines = sw.logs()
check('log.size-limit', size <= 1048576 and lines[-1]['m'] == 'entry 8999' and len(lines) > 1000, (size, len(lines)))

# ---------- 9. log of actions (errors with output) ----------
sw = Swarm({'app_web': {'node': 'Unraid', 'state': 'running'}})
sw.php('', post={'action': 'svc-restart', 'service': 'does_not_exist'})
sw.php('', post={'action': 'place', 'service': 'app_web', 'mode': 'fixed', 'node': 'raspberrypi'})
L = sw.logs()
check('action.error-logged', any(e['l'] == 'error' and e['m'] == 'Restart service does_not_exist – failed' and 'not found' in e.get('d', '') for e in L), L)
check('action.place-logged', any(e['l'] == 'info' and e['m'] == 'Placement of app_web: only raspberrypi' for e in L), L)

fails = [r for r in results if not r[1]]
print(f'\n{len(results)} watchdog checks, {len(fails)} failed')
sys.exit(1 if fails else 0)
