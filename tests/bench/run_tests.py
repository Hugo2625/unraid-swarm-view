#!/usr/bin/env python3
"""Swarm View test bench – runs every scenario (or the ones given) against the bench server.

  ./server.sh &   then   python3 run_tests.py [scenario ...] [--workers N] [--quick] [--jobs 4] [--shots] [--chrome PATH]

Scenarios run in parallel browser tabs (--jobs). Every page is checked as soon as it has settled (wait.js) instead
of after fixed pauses. Writes <out>/report.md, <out>/results.json, <out>/gallery/*.png (curated screenshots) and a
full-page screenshot of every failed scenario (--shots: of every scenario). <out> = out/ or out-w<N>/."""
import argparse, asyncio, glob, json, os, sys, time
from playwright.async_api import async_playwright

HERE = os.path.dirname(os.path.abspath(__file__))
BASE = os.environ.get('SVTB_URL', 'http://127.0.0.1:8090')
CHECKS = open(os.path.join(HERE, 'checks.js')).read()
WAIT = open(os.path.join(HERE, 'wait.js')).read()
NOISE = ('graphql', 'favicon')
# console messages caused by Unraid's own code, not by the plugins under test:
# newer Chromium blocks unload handlers (dynamix.js: jQuery/Sizzle, NchanSubscriber) and logs every attempt
CONSOLE_NOISE = ('Permissions policy violation: unload is not allowed',)
QUICK = ['default', 'fv2', 'no-fv', 'pos-top', 'basic-view', 'lang-de', 'api-error500', 'api-pi-down', 'api-slow-fv3', 'mobile', 'baseline']
# the 3 s idle measurement (no update loops) only where it says something
IDLE = {'default', 'fv2', 'no-fv', 'api-slow-fv3', 'api-slow-fv2', 'api-many', 'api-pi-down'}

ap = argparse.ArgumentParser()
ap.add_argument('scenarios', nargs='*')
ap.add_argument('--workers', type=int, default=1, help='worker nodes besides Unraid (1–3 or 6)')
ap.add_argument('--quick', action='store_true', help='small set of scenarios (for pull requests)')
ap.add_argument('--jobs', type=int, default=int(os.environ.get('SVTB_JOBS', 4)), help='scenarios in parallel')
ap.add_argument('--shots', action='store_true', help='full-page screenshot of every scenario (default: only failed ones)')
ap.add_argument('--no-shots', action='store_true', help='no full-page screenshots at all')
ap.add_argument('--no-gallery', action='store_true', help='skip the curated screenshots in <out>/gallery')
ap.add_argument('--chrome', default=os.environ.get('CHROME', '/usr/bin/google-chrome'))
args = ap.parse_args()
names = args.scenarios or (QUICK if args.quick else None) or sorted(
    os.path.basename(f)[:-5] for f in glob.glob(os.path.join(HERE, 'scenarios', '*.json'))
    if not os.path.basename(f).startswith(('world', 'api_template')))
OUT = os.path.join(HERE, 'out' if args.workers == 1 else f'out-w{args.workers}')
os.makedirs(OUT, exist_ok=True)

# Curated screenshots per run (<out>/gallery): taken right after the page settled, before the checks change it
GALLERY = {
    'default':     [('docker-page', None), ('container-list', '#docker_containers'), ('container-list-folders-open', '#docker_containers'),
                    ('swarm-section', '#sv-root')],
    'fv2':         [('container-list-fv2', '#docker_containers')],
    'no-fv':       [('container-list-no-folderview', '#docker_containers')],
    'basic-view':  [('container-list-basic', '#docker_containers')],
    'api-pi-down': [('node-down', '#sv-root')],
    'lang-de':     [('swarm-section-de', '#sv-root')],
    'theme-white': [('swarm-section-white', '#sv-root'), ('container-list-white', '#docker_containers')],
    'mobile':      [('mobile-swarm', '#sv-root'), ('mobile-page', 'viewport')],
    'pos-top':     [('position-top', 'viewport')],
}


async def settle(page, sc, quiet=400):
    await page.evaluate(WAIT)
    return await page.evaluate('([q, m]) => window.svtbSettle(q, m)', [quiet, 10000 + 2 * int(sc.get('apiDelay', 0))])


async def load(page, name, sc):
    await page.goto(f'{BASE}/Docker?s={name}&w={args.workers}', wait_until='load')
    return await settle(page, sc)


async def gallery(page, name, sc):
    gdir = os.path.join(OUT, 'gallery')
    os.makedirs(gdir, exist_ok=True)
    for label, sel in GALLERY.get(name, []):
        path = os.path.join(gdir, f'{label}.png')
        try:
            if label.endswith('folders-open'):
                # open every Folder View folder (FV3/FV2 dropdown buttons) so the members are visible
                await page.evaluate("document.querySelectorAll('#docker_list tr.folder .folder-dropdown').forEach(b => { if (b.getAttribute('active') !== 'true') b.click(); })")
                await settle(page, sc, 300)
            if sel is None:
                await page.screenshot(path=path, full_page=True)
            elif sel == 'viewport':
                await page.screenshot(path=path, full_page=False)
            else:
                await page.locator(sel).first.screenshot(path=path)
        except Exception as e:
            print(f'      (gallery {label}: {str(e)[:120]})', flush=True)


async def run_one(browser, name):
    sc = json.load(open(os.path.join(HERE, 'scenarios', f'{name}.json')))
    w, h = sc.get('_viewport', [1920, 1100])
    ctx = await browser.new_context(viewport={'width': w, 'height': h})
    page = await ctx.new_page()
    console, http = [], []
    page.on('console', lambda m: console.append(f'{m.type}: {m.text}') if m.type == 'error' and 'Failed to load resource' not in m.text
            and not any(n in m.text for n in CONSOLE_NOISE) else None)
    page.on('pageerror', lambda e: console.append(f'pageerror: {e}'))
    page.on('response', lambda r: http.append(f'{r.status} {r.url.replace(BASE, "")}') if r.status >= 400 and not any(n in r.url for n in NOISE) else None)
    t0 = time.time()
    try:
        settled = await load(page, name, sc)
        if not args.no_gallery and name in GALLERY:
            await gallery(page, name, sc)
            if name == 'default':   # folders were opened for the gallery – check a fresh page
                settled = await load(page, name, sc)
        res = await page.evaluate(CHECKS, {'stress': True, 'toggle': True, 'lock': True, 'idle': name in IDLE})
    except Exception as e:
        settled, res = None, [{'id': 'bench.evaluate', 'ok': False, 'info': str(e)[:300]}]
    # page-level errors that SVTB.errors may miss (expected 5xx of error scenarios excluded)
    expected_http = {'api-error500': '/plugins/swarm-view/include/api.php'}.get(name)
    http_bad = [x for x in http if not (expected_http and expected_http in x)]
    res.append({'id': 'http.no-unexpected-errors', 'ok': not http_bad, 'info': json.dumps(http_bad[:5])})
    res.append({'id': 'console.no-errors', 'ok': not console, 'info': json.dumps(console[:5])})
    bad = [c for c in res if not c['ok']]
    if not args.no_shots and (bad or args.shots):
        try:
            await page.screenshot(path=os.path.join(OUT, f'{name}.png'), full_page=True)
        except Exception:
            pass
    await ctx.close()
    return {'checks': res, 'seconds': round(time.time() - t0, 1), 'settledMs': settled, 'viewport': [w, h]}


async def main():
    results, done = {}, 0
    sem = asyncio.Semaphore(max(1, args.jobs))
    t_all = time.time()
    async with async_playwright() as p:
        browser = await p.chromium.launch(executable_path=args.chrome if os.path.exists(args.chrome) else None, args=['--no-sandbox'])

        async def worker(name):
            nonlocal done
            async with sem:
                r = await run_one(browser, name)
            results[name] = r
            done += 1
            bad = [c for c in r['checks'] if not c['ok']]
            print(f"[{done:2}/{len(names)}] {'OK  ' if not bad else 'FAIL'} {name:30} {len(r['checks']) - len(bad):3}/{len(r['checks']):<3} {r['seconds']:5.1f}s"
                  + ''.join(f"\n        ✗ {c['id']}: {c['info'][:220]}" for c in bad), flush=True)

        print(f'{len(names)} scenarios, {args.workers} worker(s), {args.jobs} in parallel', flush=True)
        await asyncio.gather(*(worker(n) for n in names))
        await browser.close()
    return {n: results[n] for n in names}, round(time.time() - t_all, 1)


results, secs = asyncio.run(main())
json.dump(results, open(os.path.join(OUT, 'results.json'), 'w'), indent=1)
# which Unraid / plugin versions were tested (written by setup.sh)
try:
    versions = json.load(open(os.path.join(HERE, 'vendor', 'versions.json')))
except Exception:
    versions = {}
json.dump({'workers': args.workers, 'seconds': secs, 'versions': versions}, open(os.path.join(OUT, 'meta.json'), 'w'), indent=1)
tested = (f"Unraid {versions.get('unraid') or '?'} (webgui {versions['webgui']['ref']} @ {versions['webgui']['commit']}), "
          f"Folder View 3 @ {versions['folderview3']['commit']}, Folder View 2 @ {versions['folderview2']['commit']}, "
          f"Compose Manager @ {versions['compose']['commit']}") if versions.get('webgui') else 'versions unknown'
total = sum(len(r['checks']) for r in results.values())
failed = sum(1 for r in results.values() for c in r['checks'] if not c['ok'])
with open(os.path.join(OUT, 'report.md'), 'w') as f:
    f.write(f'# Swarm View test bench – {args.workers} worker(s)\n\n{len(results)} scenarios, {total} checks, {failed} failed ({secs} s)\n\nTested against: {tested}\n\n')
    for n, r in results.items():
        bad = [c for c in r['checks'] if not c['ok']]
        f.write(f"## {'✅' if not bad else '❌'} {n}  ({r['seconds']} s)\n\n")
        for c in r['checks']:
            f.write(f"- {'✅' if c['ok'] else '❌'} `{c['id']}` {c['info'][:300]}\n")
        f.write('\n')
print(f'\n{len(results)} scenarios, {total} checks, {failed} failed in {secs} s – {tested}\nreport: {OUT}/report.md')
sys.exit(1 if failed else 0)
