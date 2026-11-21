#!/usr/bin/env python3
"""Swarm View test bench – runs every scenario (or the ones given) against the bench server.
Usage: ./server.sh &   then   python3 run_tests.py [scenario ...] [--no-shots] [--chrome /path/to/chrome]
Writes out/report.md, out/results.json and out/<scenario>.png"""
import json, os, sys, glob, time, argparse
from playwright.sync_api import sync_playwright

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, 'out')
BASE = os.environ.get('SVTB_URL', 'http://127.0.0.1:8090')
CHECKS = open(os.path.join(HERE, 'checks.js')).read()
NOISE = ('graphql', 'favicon')

ap = argparse.ArgumentParser()
ap.add_argument('scenarios', nargs='*')
ap.add_argument('--no-shots', action='store_true')
ap.add_argument('--chrome', default=os.environ.get('CHROME', '/usr/bin/google-chrome'))
ap.add_argument('--settle', type=int, default=4500, help='ms to wait after load')
ap.add_argument('--workers', type=int, default=1, help='worker nodes besides Unraid (1–3)')
ap.add_argument('--quick', action='store_true', help='small set of scenarios (for pull requests)')
args = ap.parse_args()
QUICK = ['default', 'fv2', 'no-fv', 'pos-top', 'basic-view', 'lang-de', 'api-error500', 'api-pi-down', 'api-slow-fv3', 'mobile', 'baseline']
names = args.scenarios or (QUICK if args.quick else None) or sorted(os.path.basename(f)[:-5] for f in glob.glob(os.path.join(HERE, 'scenarios', '*.json'))
                                 if not os.path.basename(f).startswith(('world', 'api_template')))
if args.workers > 1:
    OUT = os.path.join(HERE, f'out-w{args.workers}')
os.makedirs(OUT, exist_ok=True)
results = {}

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=args.chrome if os.path.exists(args.chrome) else None, args=['--no-sandbox'])
    for name in names:
        sc = json.load(open(os.path.join(HERE, 'scenarios', f'{name}.json')))
        w, h = sc.get('_viewport', [1920, 1100])
        ctx = browser.new_context(viewport={'width': w, 'height': h})
        page = ctx.new_page()
        console, http = [], []
        page.on('console', lambda m: console.append(f'{m.type}: {m.text}') if m.type == 'error' and 'Failed to load resource' not in m.text else None)
        page.on('pageerror', lambda e: console.append(f'pageerror: {e}'))
        page.on('response', lambda r: http.append(f'{r.status} {r.url.replace(BASE, "")}') if r.status >= 400 and not any(n in r.url for n in NOISE) else None)
        t0 = time.time()
        page.goto(f'{BASE}/Docker?s={name}&w={args.workers}', wait_until='load')
        page.wait_for_timeout(args.settle + int(sc.get('apiDelay', 0)))
        try:
            res = page.evaluate(CHECKS, {'stress': True, 'toggle': True, 'lock': True})
        except Exception as e:
            res = [{'id': 'bench.evaluate', 'ok': False, 'info': str(e)[:300]}]
        # page-level errors that SVTB.errors may miss (expected 5xx of error scenarios excluded)
        expected_http = {'api-error500': '/plugins/swarm-view/include/api.php'}.get(name)
        http_bad = [x for x in http if not (expected_http and expected_http in x)]
        res.append({'id': 'http.no-unexpected-errors', 'ok': not http_bad, 'info': json.dumps(http_bad[:5])})
        res.append({'id': 'console.no-errors', 'ok': not console, 'info': json.dumps(console[:5])})
        if not args.no_shots:
            page.screenshot(path=os.path.join(OUT, f'{name}.png'), full_page=True)
        results[name] = {'checks': res, 'seconds': round(time.time() - t0, 1), 'viewport': [w, h]}
        bad = [c for c in res if not c['ok']]
        print(f"{'OK  ' if not bad else 'FAIL'} {name:32} {len(res) - len(bad)}/{len(res)}" + ''.join(f"\n      ✗ {c['id']}: {c['info'][:220]}" for c in bad), flush=True)
        ctx.close()
    browser.close()

json.dump(results, open(os.path.join(OUT, 'results.json'), 'w'), indent=1)
with open(os.path.join(OUT, 'report.md'), 'w') as f:
    total = sum(len(r['checks']) for r in results.values())
    failed = sum(1 for r in results.values() for c in r['checks'] if not c['ok'])
    f.write(f'# Swarm View test bench – {args.workers} worker(s)\n\n{len(results)} scenarios, {total} checks, {failed} failed\n\n')
    for n, r in results.items():
        bad = [c for c in r['checks'] if not c['ok']]
        f.write(f"## {'✅' if not bad else '❌'} {n}\n\n")
        for c in r['checks']:
            f.write(f"- {'✅' if c['ok'] else '❌'} `{c['id']}` {c['info'][:300]}\n")
        f.write('\n')
failed_total = sum(1 for r in results.values() for c in r['checks'] if not c['ok'])
print(f'\nreport: {OUT}/report.md  ({failed_total} failed checks)')
sys.exit(1 if failed_total else 0)
