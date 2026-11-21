#!/usr/bin/env python3
"""Builds a static result page (GitHub Pages) from one or more test bench output folders.

  python3 tests/bench/make_site.py <site-dir> <out-dir> [<out-dir> ...]

Every <out-dir> is an out/ or out-w<N>/ folder of run_tests.py (report.md, results.json, gallery/*.png, <failed>.png)."""
import html, json, os, re, shutil, sys, datetime

site, outs = sys.argv[1], sys.argv[2:]
os.makedirs(os.path.join(site, 'img'), exist_ok=True)
esc = html.escape
variants = []
for d in outs:
    res_file = os.path.join(d, 'results.json')
    if not os.path.isfile(res_file):
        continue
    m = re.search(r'out-w(\d+)', d)
    workers = int(m.group(1)) if m else 1
    results = json.load(open(res_file))
    head = open(os.path.join(d, 'report.md')).read().split('\n')[2] if os.path.isfile(os.path.join(d, 'report.md')) else ''
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
            target = f'w{workers}-{kind}-{f}'
            shutil.copy(os.path.join(folder, f), os.path.join(site, 'img', target))
            imgs.append((kind, f[:-4], target))
    variants.append({'workers': workers, 'results': results, 'head': head, 'imgs': imgs})
variants.sort(key=lambda v: v['workers'])

run_url = ''
if os.environ.get('GITHUB_RUN_ID'):
    run_url = f"{os.environ.get('GITHUB_SERVER_URL', 'https://github.com')}/{os.environ.get('GITHUB_REPOSITORY', '')}/actions/runs/{os.environ['GITHUB_RUN_ID']}"
now = datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%d %H:%M UTC')
commit = os.environ.get('GITHUB_SHA', '')[:7]

parts = []
for v in variants:
    total = sum(len(r['checks']) for r in v['results'].values())
    failed = [n for n, r in v['results'].items() if any(not c['ok'] for c in r['checks'])]
    ok = not failed
    parts.append(f'<section id="w{v["workers"]}"><h2>{"✅" if ok else "❌"} {v["workers"]} worker{"s" if v["workers"] > 1 else ""} '
                 f'<small>{len(v["results"])} scenarios · {total} checks · {len(failed)} failed</small></h2>')
    if failed:
        parts.append('<p class="bad">Failed: ' + ', '.join(f'<a href="#w{v["workers"]}-{esc(n)}">{esc(n)}</a>' for n in failed) + '</p>')
    gal = [i for i in v['imgs'] if i[0] == 'gallery']
    bad_imgs = [i for i in v['imgs'] if i[0] == 'failed']
    if gal or bad_imgs:
        parts.append('<div class="grid">')
        for kind, label, target in bad_imgs + gal:
            parts.append(f'<a class="shot{" fail" if kind == "failed" else ""}" href="img/{esc(target)}" target="_blank">'
                         f'<img loading="lazy" src="img/{esc(target)}" alt="{esc(label)}"><span>{"❌ " if kind == "failed" else ""}{esc(label)}</span></a>')
        parts.append('</div>')
    parts.append('<table><thead><tr><th>Scenario</th><th>Checks</th><th>Time</th></tr></thead><tbody>')
    for n, r in v['results'].items():
        bad = [c for c in r['checks'] if not c['ok']]
        rows = ''.join(f'<li class="{"ok" if c["ok"] else "bad"}">{"✅" if c["ok"] else "❌"} <code>{esc(c["id"])}</code> {esc(str(c["info"])[:400])}</li>'
                       for c in r['checks'])
        parts.append(f'<tr id="w{v["workers"]}-{esc(n)}" class="{"bad" if bad else ""}"><td><details{" open" if bad else ""}><summary>{"❌" if bad else "✅"} {esc(n)}</summary>'
                     f'<ul>{rows}</ul></details></td><td>{len(r["checks"]) - len(bad)}/{len(r["checks"])}</td><td>{r.get("seconds", "")} s</td></tr>')
    parts.append('</tbody></table></section>')

nav = ' · '.join(f'<a href="#w{v["workers"]}">{v["workers"]} worker{"s" if v["workers"] > 1 else ""}</a>' for v in variants)
page = f'''<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Swarm View – UI tests</title>
<style>
:root {{ color-scheme: dark; }}
body {{ margin: 0; font: 15px/1.45 system-ui, sans-serif; background: #161616; color: #e8e8e8; }}
header, section {{ max-width: 1400px; margin: 0 auto; padding: 16px 20px; }}
h1 {{ margin: 8px 0 4px; font-size: 1.5em; }} h2 {{ border-bottom: 1px solid #333; padding-bottom: 6px; }}
h2 small {{ color: #9a9a9a; font-weight: normal; font-size: .65em; margin-left: 8px; }}
a {{ color: #ff8c2f; }} .meta {{ color: #9a9a9a; }} .bad {{ color: #ff6b6b; }} .ok {{ color: #cfcfcf; }}
.grid {{ display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; margin: 12px 0 20px; }}
.shot {{ display: block; background: #222; border: 1px solid #333; border-radius: 8px; overflow: hidden; text-decoration: none; color: #ddd; }}
.shot.fail {{ border-color: #ff6b6b; }} .shot:hover {{ border-color: #ff8c2f; }}
.shot img {{ width: 100%; height: 170px; object-fit: cover; object-position: top left; display: block; background: #111; }}
.shot span {{ display: block; padding: 6px 10px; font-size: .9em; }}
table {{ width: 100%; border-collapse: collapse; }} td, th {{ text-align: left; padding: 5px 8px; border-bottom: 1px solid #2a2a2a; vertical-align: top; }}
th {{ color: #9a9a9a; font-weight: normal; }} td:nth-child(2), td:nth-child(3) {{ white-space: nowrap; color: #bbb; }}
summary {{ cursor: pointer; }} ul {{ margin: 6px 0 4px; padding-left: 18px; font-size: .88em; }} li {{ margin: 2px 0; word-break: break-word; }}
code {{ color: #9fd3ff; }}
</style></head><body>
<header><h1>Swarm View – UI tests</h1>
<div class="meta">{now}{f" · commit <code>{esc(commit)}</code>" if commit else ""}{f' · <a href="{esc(run_url)}">workflow run</a>' if run_url else ""} · {nav}</div></header>
{"".join(parts) if parts else '<section><p>No results.</p></section>'}
</body></html>'''
open(os.path.join(site, 'index.html'), 'w').write(page)
open(os.path.join(site, '.nojekyll'), 'w').write('')
print(f'site: {site}/index.html – {len(variants)} variant(s), {sum(len(v["imgs"]) for v in variants)} images')
