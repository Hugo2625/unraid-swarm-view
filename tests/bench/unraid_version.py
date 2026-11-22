#!/usr/bin/env python3
"""Latest stable Unraid OS release and the webgui code that belongs to it.

  python3 tests/bench/unraid_version.py [--version 7.4.1] [--github-output]

- version: newest release-notes file in github.com/unraid/docs (docs/unraid-os/release-notes/<x.y.z>.md) –
  Unraid publishes a page there for every stable release
- webgui_ref: the release branch of that line in github.com/unraid/webgui (e.g. "7.3"), or "master" while the
  current line has no branch of its own yet
Prints JSON; with --github-output also writes version=, webgui_ref=, webgui_commit= to $GITHUB_OUTPUT."""
import json, os, re, subprocess, sys, tempfile

DOCS = 'https://github.com/unraid/docs.git'
WEBGUI = 'https://github.com/unraid/webgui.git'
NOTES = 'docs/unraid-os/release-notes/'


def git(*args, cwd=None):
    return subprocess.run(['git', *args], cwd=cwd, capture_output=True, text=True, timeout=180, check=True).stdout


def vkey(v):
    return tuple(int(x) for x in v.split('.'))


def latest_release():
    with tempfile.TemporaryDirectory() as d:
        git('clone', '-q', '--depth', '1', '--filter=blob:none', '--no-checkout', DOCS, d)
        names = git('ls-tree', '--name-only', 'HEAD', NOTES, cwd=d).split()
    versions = [m.group(1) for n in names if (m := re.fullmatch(re.escape(NOTES) + r'(\d+\.\d+\.\d+)\.mdx?', n))]
    if not versions:
        raise RuntimeError('no release notes found in unraid/docs')
    return max(versions, key=vkey)


def webgui_ref(version):
    heads = {}
    for line in git('ls-remote', '--heads', WEBGUI).splitlines():
        sha, ref = line.split('\t')
        heads[ref.removeprefix('refs/heads/')] = sha
    line_branch = '.'.join(version.split('.')[:2])
    ref = line_branch if line_branch in heads else 'master'
    return ref, heads.get(ref, '')


args = sys.argv[1:]
version = args[args.index('--version') + 1] if '--version' in args else latest_release()
ref, commit = webgui_ref(version)
info = {'version': version, 'webgui_ref': ref, 'webgui_commit': commit}
print(json.dumps(info))
if '--github-output' in args and os.environ.get('GITHUB_OUTPUT'):
    with open(os.environ['GITHUB_OUTPUT'], 'a') as f:
        for k, v in info.items():
            f.write(f'{k}={v}\n')
