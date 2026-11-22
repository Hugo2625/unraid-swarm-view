#!/usr/bin/env python3
"""Stable Unraid OS releases and the webgui code that belongs to them.

  python3 tests/bench/unraid_version.py                     latest release → {"version","released","webgui_ref","webgui_commit"}
  python3 tests/bench/unraid_version.py --version 7.3.0     the same for one release
  python3 tests/bench/unraid_version.py --list [--since 7.0]            all releases with date
  python3 tests/bench/unraid_version.py --plan SPEC [--since 7.0]       versions to test, as a JSON list
        SPEC: "" / latest | lines (newest of each x.y line) | all | explicit "7.2.9 7.3.6"
  --github-output: also write the result to $GITHUB_OUTPUT (version=…, … or versions=[…])

- releases: github.com/unraid/docs has a page for every stable release, docs/unraid-os/release-notes/<x.y.z>.md,
  whose first line names the release date ("# Version 7.3.0 2026-05-12")
- webgui code: github.com/unraid/webgui has no release tags, but a branch per release line ("7.3"; the current line
  lives on "master"). The code of a release = the last commit of its line on its release day (a close approximation:
  fixes merged later that day are included)."""
import json, os, re, subprocess, sys, tempfile

DOCS = 'https://github.com/unraid/docs.git'
WEBGUI = 'https://github.com/unraid/webgui.git'
NOTES = 'docs/unraid-os/release-notes/'
TMP = tempfile.mkdtemp(prefix='unraid-version-')


def git(*args, cwd=None):
    return subprocess.run(['git', *args], cwd=cwd, capture_output=True, text=True, timeout=180, check=True).stdout


def vkey(v):
    return tuple(int(x) for x in v.split('.'))


def releases():
    """{version: release date} of all stable releases"""
    d = os.path.join(TMP, 'docs')
    # sparse checkout of the release-notes folder only: all its files arrive in one request
    git('clone', '-q', '--depth', '1', '--filter=blob:none', '--sparse', DOCS, d)
    git('sparse-checkout', 'set', '--no-cone', f'/{NOTES}', cwd=d)
    dates = {}
    for n in os.listdir(os.path.join(d, NOTES)):
        m = re.fullmatch(r'(\d+\.\d+\.\d+)\.mdx?', n)
        if not m:
            continue
        head = open(os.path.join(d, NOTES, n), errors='replace').read(2000)
        dm = re.search(r'^#+\s*Version\s+\S+\s+(\d{4}-\d{2}-\d{2})', head, re.M)
        dates[m.group(1)] = dm.group(1) if dm else ''
    if not dates:
        raise RuntimeError('no release notes found in unraid/docs')
    return dict(sorted(dates.items(), key=lambda kv: vkey(kv[0])))


_heads = None


def webgui(version, released):
    """(ref, commit) of the webgui code for a release"""
    global _heads
    if _heads is None:
        _heads = {}
        for line in git('ls-remote', '--heads', WEBGUI).splitlines():
            sha, ref = line.split('\t')
            _heads[ref.removeprefix('refs/heads/')] = sha
    line_branch = '.'.join(version.split('.')[:2])
    ref = line_branch if line_branch in _heads else 'master'
    if not released:
        return ref, _heads.get(ref, '')
    d = os.path.join(TMP, 'webgui')
    if not os.path.isdir(d):
        git('init', '-q', d)
    if not git('for-each-ref', f'refs/remotes/o/{ref}', cwd=d).strip():
        git('fetch', '-q', '--filter=tree:0', '--no-tags', WEBGUI, f'refs/heads/{ref}:refs/remotes/o/{ref}', cwd=d)
    commit = git('rev-list', '-1', f'--before={released}T23:59:59Z', f'refs/remotes/o/{ref}', cwd=d).strip()
    return ref, commit or _heads.get(ref, '')


def output(pairs):
    if '--github-output' in args and os.environ.get('GITHUB_OUTPUT'):
        with open(os.environ['GITHUB_OUTPUT'], 'a') as f:
            for k, v in pairs.items():
                f.write(f'{k}={v if isinstance(v, str) else json.dumps(v)}\n')


args = sys.argv[1:]
since = vkey(args[args.index('--since') + 1]) if '--since' in args else (7, 0)
rel = releases()
mine = {v: d for v, d in rel.items() if vkey(v)[:len(since)] >= since}

if '--list' in args:
    print(json.dumps([{'version': v, 'released': d} for v, d in mine.items()], indent=1))
elif '--plan' in args:
    spec = args[args.index('--plan') + 1].strip().lower()
    latest = max(rel, key=vkey)
    if spec in ('', 'latest'):
        plan = [latest]
    elif spec == 'all':
        plan = list(mine)
    elif spec == 'lines':
        best = {}
        for v in mine:
            best['.'.join(v.split('.')[:2])] = v
        plan = list(best.values())
    else:
        plan = [v for v in re.split(r'[\s,;]+', spec) if v]
        unknown = [v for v in plan if v not in rel]
        if unknown:
            sys.exit(f'unknown Unraid release(s): {", ".join(unknown)} – known: {", ".join(rel)}')
    plan = sorted(dict.fromkeys(plan), key=vkey, reverse=True)
    print(json.dumps(plan))
    output({'versions': plan})
else:
    version = args[args.index('--version') + 1] if '--version' in args else max(rel, key=vkey)
    if version not in rel:
        sys.exit(f'unknown Unraid release {version}')
    ref, commit = webgui(version, rel[version])
    info = {'version': version, 'released': rel[version], 'webgui_ref': ref, 'webgui_commit': commit}
    print(json.dumps(info))
    output(info)
