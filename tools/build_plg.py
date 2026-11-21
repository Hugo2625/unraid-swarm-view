#!/usr/bin/env python3
"""Build swarm-view.plg (all files inline) from src/swarm-view.

Usage:  python3 tools/build_plg.py <version> [--out FILE]     e.g. 2026.11.21
Writes swarm-view.plg in the repository root (or FILE). Edit CHANGES.md first: the
section of the new version becomes the <CHANGES> block of the .plg.
"""
import pathlib, re, sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
SRC = ROOT / 'src' / 'swarm-view'
OUT = ROOT / 'swarm-view.plg'
GITHUB = 'Hugo2625/unraid-swarm-view'
BRANCH = 'main'
SUPPORT = 'https://forums.unraid.net/topic/201367-plugin-support-swarm-view-docker-swarm-stacks-on-the-docker-page/'

args = sys.argv[1:]
if '--out' in args:
    i = args.index('--out')
    OUT = pathlib.Path(args[i + 1]).resolve()
    del args[i:i + 2]
if len(args) != 1 or not re.fullmatch(r'\d{4}\.\d{2}\.\d{2}[a-z]?', args[0]):
    sys.exit('usage: build_plg.py <version YYYY.MM.DD[letter]> [--out FILE]')
version = args[0]

changes = (ROOT / 'CHANGES.md').read_text()
if f'### {version}' not in changes:
    sys.exit(f'CHANGES.md has no section "### {version}"')
changes_xml = changes.split('\n', 1)[1].replace('&', '&amp;').replace('<', '&lt;').replace('### ', '###').strip()

parts = []
for p in sorted(x for x in SRC.rglob('*') if x.is_file()):
    rel = p.relative_to(SRC).as_posix()
    body = p.read_text()
    if ']]>' in body:
        sys.exit(f'{rel} contains "]]>" – cannot be inlined as CDATA')
    parts.append(f'<FILE Name="&plugdir;/{rel}">\n<INLINE><![CDATA[{body}]]></INLINE>\n</FILE>\n')

support = ' support="&support;"' if SUPPORT else ''
plg = f"""<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
<!ENTITY name      "swarm-view">
<!ENTITY author    "Hugo2625">
<!ENTITY version   "{version}">
<!ENTITY github    "{GITHUB}">
<!ENTITY pluginURL "https://raw.githubusercontent.com/&github;/{BRANCH}/&name;.plg">
<!ENTITY support   "{SUPPORT}">
<!ENTITY plugdir   "/usr/local/emhttp/plugins/&name;">
]>
<PLUGIN name="&name;" author="&author;" version="&version;" pluginURL="&pluginURL;"{support} min="7.0.0" icon="cubes">

<CHANGES>
{changes_xml}
</CHANGES>

<!-- Remove an older version first (the installer skips files that already exist) -->
<FILE Run="/bin/bash">
<INLINE>
rm -rf &plugdir;
</INLINE>
</FILE>

{''.join(parts)}
<FILE Run="/bin/bash">
<INLINE>
chmod -R u=rwX,go=rX &plugdir;
# activate the saved cleanup schedule (cleanup.cron)
if [ -x /usr/local/sbin/update_cron ]; then /usr/local/sbin/update_cron; fi
# bring the CPU/RAM agent to the new version (if enabled)
timeout 60 /usr/bin/php -q &plugdir;/scripts/postinstall.php || true
echo ""
echo "Swarm View &version; installed - reload the Docker page."
</INLINE>
</FILE>

<FILE Run="/bin/bash" Method="remove">
<INLINE>
rm -rf &plugdir;
rm -f /boot/config/plugins/&name;/cleanup.cron
# remove helper services from the swarm (agent on all nodes, cleanup job)
if [ -x /usr/bin/docker ]; then /usr/bin/docker service rm swarm-view-agent swarm-view-cleanup >/dev/null 2>&amp;1; fi
rm -rf /tmp/swarm-view
if [ -x /usr/local/sbin/update_cron ]; then /usr/local/sbin/update_cron; fi
echo "Swarm View removed (stack files and settings stay in /boot/config/plugins/&name;/)."
</INLINE>
</FILE>

</PLUGIN>
"""
OUT.write_text(plg)
print(f'{OUT.name}: {len(parts)} files, {OUT.stat().st_size} bytes, version {version}')
