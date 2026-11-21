#!/usr/bin/env python3
"""Check translations: every text passed to t()/tn() (JS) and sv_t()/sv_e()/sv_tn() (PHP)
must exist in each lang/<code>.json. Prints missing and unused keys."""
import json, pathlib, re, sys

SRC = pathlib.Path(__file__).resolve().parent.parent / 'src' / 'swarm-view'
STR = r"""('(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*")"""
CALLS = [re.compile(r'\b(?:t|sv_t|sv_e)\(\s*' + STR),
         re.compile(r'\b(?:tn|sv_tn)\([^,()]+(?:\([^()]*\))?\s*,\s*' + STR + r'\s*,\s*' + STR)]

def unquote(s):
    q, body = s[0], s[1:-1]
    return re.sub(r'\\(.)', r'\1', body)

used = {}
for f in sorted(SRC.rglob('*')):
    if f.suffix not in ('.js', '.php', '.page'):
        continue
    text = f.read_text()
    for rx in CALLS:
        for m in rx.finditer(text):
            for g in m.groups():
                used.setdefault(unquote(g), f.relative_to(SRC).as_posix())
# keys used through variables
for k in ['(no stack)', 'daily', 'weekly']:
    used.setdefault(k, '(dynamic)')

bad = 0
for lf in sorted((SRC / 'lang').glob('*.json')):
    d = json.loads(lf.read_text())
    missing = [k for k in used if k not in d]
    unused = [k for k in d if k not in used]
    print(f'{lf.name}: {len(d)} entries, {len(used)} texts in use, {len(missing)} missing, {len(unused)} unused')
    for k in missing: print(f'  MISSING  {k!r}  ({used[k]})')
    for k in unused: print(f'  unused   {k!r}')
    bad += len(missing)
sys.exit(1 if bad else 0)
