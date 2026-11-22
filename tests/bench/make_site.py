#!/usr/bin/env python3
"""Builds the static results site (GitHub Pages) from one or more test bench output folders – with a history.

  python3 tests/bench/make_site.py <site-dir> <out-dir> [<out-dir> ...] [--prev <url-or-dir>]

Every <out-dir> is an out/ or out-w<N>/ folder of run_tests.py (report.md, results.json, meta.json, gallery/*.png,
<failed>.png). The folders are grouped by the Unraid version in their meta.json – one run can test several versions.

--prev: the currently published site (URL) or a local copy. Its history.json and, per Unraid version, the page and
screenshots of the latest run are taken over, so the site keeps
  index.html              overview: compatibility per Unraid version, screenshots of the newest version, all runs
  unraid/<version>/       report + screenshots of the latest run against that version
  history.json            every run: date, Unraid / webgui, Folder View 3/2, Compose Manager, Swarm View version, result
  badge.json              README badge: newest Unraid version and whether its latest run passed
No commits are needed – the published site is the archive."""
import concurrent.futures, datetime, html, json, os, re, shutil, sys, tempfile, urllib.parse, urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
esc = html.escape
HISTORY_MAX = 500

args = sys.argv[1:]
prev = None
if '--prev' in args:
    i = args.index('--prev')
    prev = args[i + 1]
    del args[i:i + 2]
site, outs = args[0], args[1:]


def vkey(v):
    """sort key for versions like 7.4.1 / 7.10.0-rc.1 (unknown last)"""
    nums = [int(x) for x in re.findall(r'\d+', v or '')]
    return nums or [-1]


def plugin_version():
    try:
        m = re.search(r'<!ENTITY version\s+"([^"]+)"', open(os.path.join(HERE, '..', '..', 'swarm-view.plg')).read())
        return m.group(1) if m else ''
    except OSError:
        return ''


# ---------- this run (one entry per tested Unraid version) ----------
variants = []
for d in outs:
    res_file = os.path.join(d, 'results.json')
    if not os.path.isfile(res_file):
        continue
    m = re.search(r'out-w(\d+)', d)
    workers = int(m.group(1)) if m else 1
    results = json.load(open(res_file))
    imgs = []
    for sub, kind in (('gallery', 'gallery'), ('', 'failed')):
        folder = os.path.join(d, sub)
        if not os.path.isdir(folder):
            continue
        for f in sorted(os.listdir(folder)):
            if not f.endswith('.png'):
                continue
            # only screenshots of scenarios that failed in THIS run (old files may lie around locally)
            if kind == 'failed' and not any(not c['ok'] for c in results.get(f[:-4], {}).get('checks', [{'ok': True}])):
                continue
            imgs.append((kind, f[:-4], f'w{workers}-{kind}-{f}', os.path.join(folder, f)))
    meta = json.load(open(os.path.join(d, 'meta.json'))) if os.path.isfile(os.path.join(d, 'meta.json')) else {}
    vv = meta.get('versions') or {}
    variants.append({'workers': workers, 'results': results, 'imgs': imgs, 'versions': vv, 'unraid': vv.get('unraid') or 'unknown'})
if not variants:
    sys.exit('make_site: no results')
groups = {}
for v in sorted(variants, key=lambda v: v['workers']):
    groups.setdefault(v['unraid'], []).append(v)

now = datetime.datetime.now(datetime.timezone.utc)
gh = os.environ.get('GITHUB_RUN_ID')
run_url = f"{os.environ.get('GITHUB_SERVER_URL', 'https://github.com')}/{os.environ.get('GITHUB_REPOSITORY', '')}/actions/runs/{gh}" if gh else ''
entries = []
for unraid, vs in sorted(groups.items(), key=lambda kv: vkey(kv[0]), reverse=True):
    vers = next((v['versions'] for v in vs if v['versions'].get('webgui')), {})
    summary = []
    for v in vs:
        failed = [n for n, r in v['results'].items() if any(not c['ok'] for c in r['checks'])]
        summary.append({'workers': v['workers'], 'scenarios': len(v['results']), 'checks': sum(len(r['checks']) for r in v['results'].values()), 'failed': failed})
    entries.append({
        'id': f"{gh or now.strftime('local-%Y%m%d%H%M%S')}-{unraid}",
        'date': now.strftime('%Y-%m-%dT%H:%M:%SZ'),
        'unraid': unraid,
        'released': vers.get('released', ''),
        'webgui': vers.get('webgui') or {},
        'folderview3': (vers.get('folderview3') or {}).get('commit', ''),
        'folderview2': (vers.get('folderview2') or {}).get('commit', ''),
        'compose': (vers.get('compose') or {}).get('commit', ''),
        'plugin': plugin_version(),
        'commit': os.environ.get('GITHUB_SHA', '')[:7],
        'event': os.environ.get('GITHUB_EVENT_NAME', 'local'),
        'run_url': run_url,
        'ok': all(not x['failed'] for x in summary),
        'variants': summary,
        'dir': f'unraid/{re.sub(r"[^A-Za-z0-9._-]", "_", unraid)}',
        'files': [],
        '_variants': vs,
    })
tested_now = {e['unraid'] for e in entries}

# ---------- previous site ----------
history, prev_dir = [], None
if prev:
    if re.match(r'https?://', prev):
        base = prev.rstrip('/') + '/'
        prev_dir = tempfile.mkdtemp(prefix='prev-site-')

        def get(rel):
            url = base + urllib.parse.quote(rel)
            target = os.path.join(prev_dir, rel)
            os.makedirs(os.path.dirname(target), exist_ok=True)
            try:
                with urllib.request.urlopen(url, timeout=30) as r, open(target, 'wb') as f:
                    shutil.copyfileobj(r, f)
                return True
            except Exception:
                return False
        if get('history.json'):
            try:
                h = json.load(open(os.path.join(prev_dir, 'history.json')))
            except ValueError:
                h = []
            want, seen = [], set()
            for e in h:
                if e.get('unraid') in seen or e.get('unraid') in tested_now:
                    continue
                seen.add(e.get('unraid'))
                want += [f"{e['dir']}/{f}" for f in e.get('files', [])]
            with concurrent.futures.ThreadPoolExecutor(8) as ex:
                got = list(ex.map(get, want))
            print(f'previous site: {len(h)} runs, {sum(got)}/{len(want)} files of {len(seen)} other Unraid version(s)')
        else:
            print(f'previous site: no history.json at {base} (first run with history)')
    elif os.path.isdir(prev):
        prev_dir = prev
    if prev_dir and os.path.isfile(os.path.join(prev_dir, 'history.json')):
        try:
            history = json.load(open(os.path.join(prev_dir, 'history.json')))
        except ValueError:
            history = []

# ---------- page parts ----------
CSS = '''
:root { color-scheme: dark; }
body { margin: 0; font: 15px/1.45 system-ui, sans-serif; background: #161616; color: #e8e8e8; }
header, section { max-width: 1400px; margin: 0 auto; padding: 16px 20px; }
h1 { margin: 8px 0 4px; font-size: 1.5em; } h2 { border-bottom: 1px solid #333; padding-bottom: 6px; } h3 { margin: 18px 0 0; font-size: 1.05em; color: #cfcfcf; }
h2 small { color: #9a9a9a; font-weight: normal; font-size: .65em; margin-left: 8px; }
a { color: #ff8c2f; } .meta { color: #9a9a9a; } .bad { color: #ff6b6b; } .ok { color: #cfcfcf; } .good { color: #6bd66b; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; margin: 12px 0 20px; }
.tested { margin-top: 6px; color: #cfcfcf; }
.shot { display: block; background: #222; border: 1px solid #333; border-radius: 8px; overflow: hidden; text-decoration: none; color: #ddd; }
.shot.fail { border-color: #ff6b6b; } .shot:hover { border-color: #ff8c2f; }
.shot img { width: 100%; height: 170px; object-fit: cover; object-position: top left; display: block; background: #111; }
.shot span { display: block; padding: 6px 10px; font-size: .9em; }
table { width: 100%; border-collapse: collapse; } td, th { text-align: left; padding: 5px 8px; border-bottom: 1px solid #2a2a2a; vertical-align: top; }
th { color: #9a9a9a; font-weight: normal; white-space: nowrap; }
table.report td:nth-child(2), table.report td:nth-child(3) { white-space: nowrap; color: #bbb; }
table.list td { white-space: nowrap; } table.list td.wrap { white-space: normal; }
summary { cursor: pointer; } ul { margin: 6px 0 4px; padding-left: 18px; font-size: .88em; } li { margin: 2px 0; word-break: break-word; }
code { color: #9fd3ff; } .scroll { overflow-x: auto; }
'''


def page(title, body):
    return (f'<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            f'<title>{esc(title)}</title><style>{CSS}</style></head><body>{body}</body></html>')


def tested_line(e):
    w = e.get('webgui') or {}
    if not w:
        return ''
    return (f'<div class="tested">Tested against <b>Unraid {esc(e["unraid"])}</b> (webgui <code>{esc(w.get("ref", ""))}</code> @ <code>{esc(w.get("commit", ""))}</code>, '
            f'{esc(w.get("date", ""))}) · Folder View 3 @ <code>{esc(e["folderview3"])}</code> · Folder View 2 @ <code>{esc(e["folderview2"])}</code> · '
            f'Compose Manager @ <code>{esc(e["compose"])}</code> · Swarm View <b>{esc(e["plugin"])}</b></div>')


def when(e):
    return e['date'].replace('T', ' ')[:16] + ' UTC'


def gallery(imgs, prefix=''):
    """imgs: (kind, label, file) – file relative to prefix"""
    if not imgs:
        return ''
    imgs = [i for i in imgs if i[0] == 'failed'] + [i for i in imgs if i[0] != 'failed']
    return '<div class="grid">' + ''.join(
        f'<a class="shot{" fail" if k == "failed" else ""}" href="{esc(prefix + f)}" target="_blank"><img loading="lazy" src="{esc(prefix + f)}" alt="{esc(lbl)}">'
        f'<span>{"❌ " if k == "failed" else ""}{esc(lbl)}</span></a>' for k, lbl, f in imgs) + '</div>'


# ---------- report page per tested version: unraid/<version>/ ----------
def report(entry):
    vs = entry.pop('_variants')
    vdir = os.path.join(site, entry['dir'])
    if os.path.isdir(vdir):
        shutil.rmtree(vdir)
    os.makedirs(os.path.join(vdir, 'img'), exist_ok=True)
    parts = []
    for v, s in zip(vs, entry['variants']):
        parts.append(f'<section id="w{v["workers"]}"><h2>{"✅" if not s["failed"] else "❌"} {v["workers"]} worker{"s" if v["workers"] > 1 else ""} '
                     f'<small>{s["scenarios"]} scenarios · {s["checks"]} checks · {len(s["failed"])} failed</small></h2>')
        if s['failed']:
            parts.append('<p class="bad">Failed: ' + ', '.join(f'<a href="#w{v["workers"]}-{esc(n)}">{esc(n)}</a>' for n in s['failed']) + '</p>')
        for kind, label, target, src in v['imgs']:
            shutil.copy(src, os.path.join(vdir, 'img', target))
            entry['files'].append(f'img/{target}')
        parts.append(gallery([(k, lbl, f'img/{t}') for k, lbl, t, _ in v['imgs']]))
        parts.append('<table class="report"><thead><tr><th>Scenario</th><th>Checks</th><th>Time</th></tr></thead><tbody>')
        for n, r in v['results'].items():
            bad = [c for c in r['checks'] if not c['ok']]
            rows = ''.join(f'<li class="{"ok" if c["ok"] else "bad"}">{"✅" if c["ok"] else "❌"} <code>{esc(c["id"])}</code> {esc(str(c["info"])[:400])}</li>'
                           for c in r['checks'])
            parts.append(f'<tr id="w{v["workers"]}-{esc(n)}" class="{"bad" if bad else ""}"><td><details{" open" if bad else ""}><summary>{"❌" if bad else "✅"} {esc(n)}</summary>'
                         f'<ul>{rows}</ul></details></td><td>{len(r["checks"]) - len(bad)}/{len(r["checks"])}</td><td>{r.get("seconds", "")} s</td></tr>')
        parts.append('</tbody></table></section>')
    nav = ' · '.join(f'<a href="#w{v["workers"]}">{v["workers"]} worker{"s" if v["workers"] > 1 else ""}</a>' for v in vs)
    commitinfo = f' · commit <code>{esc(entry["commit"])}</code>' if entry['commit'] else ''
    body = (f'<header><div class="meta"><a href="../../">← all Unraid versions</a></div><h1>Swarm View – UI tests – Unraid {esc(entry["unraid"])}</h1>'
            f'<div class="meta">{when(entry)}{commitinfo}{runlink} · {nav}</div>{tested_line(entry)}</header>' + ''.join(parts))
    open(os.path.join(vdir, 'index.html'), 'w').write(page(f'Swarm View – UI tests – Unraid {entry["unraid"]}', body))
    entry['files'].append('index.html')


runlink = f' · <a href="{esc(run_url)}">workflow run</a>' if run_url else ''
for e in entries:
    report(e)

# ---------- history: this run + previous runs; pages of the other versions ----------
ids = {e['id'] for e in entries}
history = entries + [e for e in history if e.get('id') not in ids]
history = history[:HISTORY_MAX]
latest = {}                                   # Unraid version → latest run
for e in history:
    latest.setdefault(e.get('unraid', 'unknown'), e)
for ver, e in latest.items():
    if e in entries:
        continue
    src = os.path.join(prev_dir, e['dir']) if prev_dir else None
    have = [f for f in e.get('files', []) if src and os.path.isfile(os.path.join(src, f))]
    for f in have:
        os.makedirs(os.path.dirname(os.path.join(site, e['dir'], f)), exist_ok=True)
        shutil.copy(os.path.join(src, f), os.path.join(site, e['dir'], f))
    if len(have) != len(e.get('files', [])):
        e['files'] = have if 'index.html' in have else []      # page lost → link to the workflow run instead
json.dump(history, open(os.path.join(site, 'history.json'), 'w'), indent=1)


# ---------- overview: index.html ----------
def result(e):
    if e['ok']:
        return '<span class="good">✅ passed</span>'
    n = sum(len(s['failed']) for s in e['variants'])
    return f'<span class="bad">❌ {n} scenario{"s" if n != 1 else ""} failed</span>'


def link(e, text):
    if e.get('files'):
        return f'<a href="{esc(e["dir"])}/">{text}</a>'
    return f'<a href="{esc(e["run_url"])}">{text}</a>' if e.get('run_url') else text


def last_passing_plugin(ver):
    return next((e['plugin'] for e in history if e.get('unraid') == ver and e['ok']), '')


versions = sorted(latest, key=vkey, reverse=True)
newest = latest[versions[0]]
rows = []
for ver in versions:
    e = latest[ver]
    lp = last_passing_plugin(ver)
    rel = f' <small class="meta">{esc(e["released"])}</small>' if e.get('released') else ''
    rows.append(f'<tr><td><b>{link(e, "Unraid " + esc(ver))}</b>{rel}</td><td>{result(e)}</td><td>{esc(e["plugin"])}</td>'
                f'<td>{esc(lp) if lp else "–"}</td><td>{esc(when(e))}</td>'
                f'<td><code>{esc((e.get("webgui") or {}).get("ref", ""))}</code> @ <code>{esc((e.get("webgui") or {}).get("commit", ""))}</code></td>'
                f'<td><code>{esc(e["folderview3"])}</code></td><td><code>{esc(e["folderview2"])}</code></td><td><code>{esc(e["compose"])}</code></td>'
                f'<td>{link(e, "screenshots &amp; checks")}</td></tr>')
compat = ('<section><h2>Compatibility <small>latest run per Unraid version</small></h2><div class="scroll"><table class="list"><thead><tr>'
          '<th>Unraid</th><th>Result</th><th>Swarm View</th><th>Last passing Swarm View</th><th>Tested</th><th>webgui</th><th>Folder View 3</th>'
          '<th>Folder View 2</th><th>Compose Manager</th><th></th></tr></thead><tbody>' + ''.join(rows) + '</tbody></table></div></section>')

groups = {}                                   # workers → screenshots of the newest version
for f in newest.get('files', []):
    m = re.match(r'img/w(\d+)-(gallery|failed)-(.+)\.png$', f)
    if m:
        groups.setdefault(int(m.group(1)), []).append((m.group(2), m.group(3), f'{newest["dir"]}/{f}'))
shots = (f'<section><h2>Screenshots – Unraid {esc(newest["unraid"])} <small>{esc(when(newest))} · {link(newest, "full report")}</small></h2>'
         + (''.join(f'<h3>{w} worker{"s" if w != 1 else ""}</h3>' + gallery(g) for w, g in sorted(groups.items()))
            if groups else '<p class="meta">No screenshots.</p>') + '</section>')

hrows = []
for i, e in enumerate(history):
    var = ', '.join(str(s['workers']) for s in e['variants'])
    rl = f'<a href="{esc(e["run_url"])}">run</a>' if e.get('run_url') else ''
    hrows.append(f'<tr><td>{esc(when(e))}</td><td>Unraid {esc(e["unraid"])}</td><td>{result(e)}</td><td>{esc(e["plugin"])}</td>'
                 f'<td><code>{esc(e["folderview3"])}</code></td><td><code>{esc(e["folderview2"])}</code></td><td><code>{esc(e["compose"])}</code></td>'
                 f'<td>{esc(var)}</td><td>{esc(e["event"])}</td><td>{rl}</td></tr>')
hist = ('<section><h2>All runs <small>' + str(len(history)) + ' (newest first)</small></h2><div class="scroll"><table class="list"><thead><tr>'
        '<th>Date</th><th>Unraid</th><th>Result</th><th>Swarm View</th><th>Folder View 3</th><th>Folder View 2</th><th>Compose Manager</th>'
        '<th>Workers</th><th>Trigger</th><th></th></tr></thead><tbody>' + ''.join(hrows) + '</tbody></table></div></section>')

if len(entries) == 1:
    lastrun = f'Unraid {esc(entries[0]["unraid"])} – {result(entries[0])}'
else:
    bad = [e['unraid'] for e in entries if not e['ok']]
    lastrun = (f'{len(entries)} Unraid versions – <span class="good">{len(entries) - len(bad)} passed</span>'
               + (f', <span class="bad">failed: {esc(", ".join(bad))}</span>' if bad else ''))
head = (f'<header><h1>Swarm View – UI tests</h1><div class="meta">The original Unraid Docker page of each Unraid release (its webgui code on the '
        f'release day) with Folder View 3/2, Compose Manager and Swarm View in headless Chrome, with 1, 2 and 6 worker nodes. '
        f'Latest run: {esc(when(entries[0]))}, {lastrun}{runlink}</div></header>')
open(os.path.join(site, 'index.html'), 'w').write(page('Swarm View – UI tests', head + compat + shots + hist))
open(os.path.join(site, '.nojekyll'), 'w').write('')

# README badge (shields.io endpoint): newest Unraid version and whether its latest run passed
# (a manual run against an older version does not change it)
badge = {'schemaVersion': 1, 'label': 'tested on Unraid', 'namedLogo': 'unraid',
         'message': newest['unraid'] + ('' if newest['ok'] else ' failing'),
         'color': ('brightgreen' if newest['unraid'] != 'unknown' else 'green') if newest['ok'] else 'red'}
json.dump(badge, open(os.path.join(site, 'badge.json'), 'w'))
print(f'site: {site}/ – ' + ', '.join(f'Unraid {e["unraid"]}: {"passed" if e["ok"] else "FAILED"} ({len(e["files"]) - 1} images)' for e in entries)
      + f'; {len(history)} runs, {len(versions)} Unraid version(s); badge: {badge["message"]}')
