#!/usr/bin/env python3
"""Fast checks for CI (and before every commit):

  python3 tools/ci_checks.py [--base <git ref>]

- PHP syntax of every .php file and of the PHP part of every .page file (short tags on, like Unraid)
- JavaScript syntax (node --check), JSON of the translation files
- translations complete (tools/i18n_check.py)
- swarm-view.plg is exactly what tools/build_plg.py builds from src/ (nobody forgot to rebuild)
- with --base: if src/ changed against <base>, the plugin version must be higher – otherwise Unraid offers no update
"""
import json, pathlib, re, subprocess, sys, tempfile

ROOT = pathlib.Path(__file__).resolve().parent.parent
SRC = ROOT / 'src' / 'swarm-view'
fails = []


def ok(name, good, info=''):
    print(('ok    ' if good else 'FAIL  ') + name + ('' if good or not info else f'\n      {info}'))
    if not good:
        fails.append(name)


def run(cmd, **kw):
    return subprocess.run(cmd, capture_output=True, text=True, cwd=ROOT, **kw)


def plg_version(text):
    m = re.search(r'<!ENTITY version\s+"([^"]+)">', text)
    return m.group(1) if m else ''


# --- PHP ---
bad = []
for p in sorted(SRC.rglob('*.php')):
    r = run(['php', '-d', 'short_open_tag=1', '-l', str(p)])
    if r.returncode:
        bad.append(f'{p.relative_to(ROOT)}: {r.stdout.strip() or r.stderr.strip()}')
for p in sorted(SRC.rglob('*.page')):
    body = p.read_text().split('\n---\n', 1)[-1]
    with tempfile.NamedTemporaryFile('w', suffix='.php', delete=False) as t:
        t.write(body)
    r = run(['php', '-d', 'short_open_tag=1', '-l', t.name])
    if r.returncode:
        bad.append(f'{p.relative_to(ROOT)}: {r.stdout.strip() or r.stderr.strip()}')
ok('php syntax', not bad, '\n      '.join(bad))

# --- JavaScript / JSON ---
bad = []
for p in sorted(SRC.rglob('*.js')):
    r = run(['node', '--check', str(p)])
    if r.returncode:
        bad.append(f'{p.relative_to(ROOT)}: {r.stderr.strip().splitlines()[0] if r.stderr.strip() else "?"}')
ok('js syntax', not bad, '\n      '.join(bad))
bad = []
for p in sorted((SRC / 'lang').glob('*.json')):
    try:
        json.loads(p.read_text())
    except Exception as e:
        bad.append(f'{p.name}: {e}')
ok('translation files are valid JSON', not bad, '\n      '.join(bad))

# --- translations complete ---
r = run([sys.executable, 'tools/i18n_check.py'])
ok('translations complete', r.returncode == 0, (r.stdout + r.stderr).strip()[-600:])

# --- .plg in sync with src/ ---
committed = (ROOT / 'swarm-view.plg').read_text()
version = plg_version(committed)
with tempfile.TemporaryDirectory() as d:
    r = run([sys.executable, 'tools/build_plg.py', version, '--out', f'{d}/swarm-view.plg'])
    built = pathlib.Path(f'{d}/swarm-view.plg').read_text() if r.returncode == 0 else ''
ok(f'swarm-view.plg matches src/ (version {version})', r.returncode == 0 and built == committed,
   (r.stderr.strip() or 'swarm-view.plg is out of date – run: python3 tools/build_plg.py ' + version))

# --- version bump ---
if '--base' in sys.argv:
    base = sys.argv[sys.argv.index('--base') + 1]
    if run(['git', 'rev-parse', '--verify', '--quiet', base]).returncode != 0:
        ok(f'version bump (base {base} not found – skipped)', True)
    else:
        changed = run(['git', 'diff', '--quiet', base, '--', 'src/']).returncode != 0
        old = plg_version(run(['git', 'show', f'{base}:swarm-view.plg']).stdout)
        if not changed:
            ok('version bump (src/ unchanged)', True)
        else:
            ok(f'version raised because src/ changed ({old} → {version})', bool(old) and version > old,
               f'src/ changed but the version is still {version} – Unraid would not offer the update')

print(f'\n{len(fails)} failed' if fails else '\nall checks passed')
sys.exit(1 if fails else 0)
