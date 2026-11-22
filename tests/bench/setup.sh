#!/bin/bash
# Swarm View test bench – setup.
# Builds a fake Unraid web root (/usr/local/emhttp) and /boot/config from the vendor sources,
# so the original Unraid, FolderView and Compose Manager pages run unchanged.
# Run as root in a throwaway container/VM – it writes to /usr/local/emhttp and /boot/config.
#
# Unraid code: by default the webgui of the latest stable Unraid release (unraid_version.py): the last commit of its
# release line (branch "7.3", or "master" for the current line) on the release day.
# Override: UNRAID_VERSION=7.3.0 (any stable release) or WEBGUI_REF=<branch or commit>.
# Folder View 3/2 and Compose Manager: their current default branch.
set -e
BENCH="$(cd "$(dirname "$0")" && pwd)"
# Swarm View checkout: the repository this bench lives in (tests/bench), or SV_REPO
if [ -n "$SV_REPO" ]; then REPO="$SV_REPO"; elif [ -d "$BENCH/../../src/swarm-view" ]; then REPO="$(cd "$BENCH/../.." && pwd)"; else REPO="$BENCH/../unraid-swarm-view"; fi
V="$BENCH/vendor"

clone() { # url dir [sparse paths...]
  local url=$1 dir=$2; shift 2
  [ -d "$V/$dir" ] && return
  if [ $# -gt 0 ]; then
    git clone -q --depth 1 --filter=blob:none --sparse "$url" "$V/$dir"
    git -C "$V/$dir" sparse-checkout set "$@"
  else
    git clone -q --depth 1 "$url" "$V/$dir"
  fi
}
mkdir -p "$V"
clone https://github.com/unraid/webgui.git webgui \
  emhttp/plugins/dynamix.docker.manager emhttp/plugins/dynamix/include emhttp/plugins/dynamix/javascript \
  emhttp/plugins/dynamix/styles emhttp/plugins/dynamix/scripts emhttp/plugins/dynamix.vm.manager/include
clone https://github.com/chodeus/folder.view3.git folder.view3
clone https://github.com/VladoPortos/folder.view2.git folder.view2
clone https://github.com/dcflachs/compose_plugin.git compose_plugin
[ "${1:-}" = "--update" ] && for d in "$V"/folder.view3 "$V"/folder.view2 "$V"/compose_plugin; do git -C "$d" pull -q || true; done

# --- Unraid release → webgui code of that release (last commit of its line on the release day) ---
WEBGUI_LINE=""; RELEASED=""
if [ -z "$WEBGUI_REF" ]; then
  if ! info=$(python3 "$BENCH/unraid_version.py" ${UNRAID_VERSION:+--version "$UNRAID_VERSION"}); then
    [ -n "$UNRAID_VERSION" ] && { echo "setup: Unraid $UNRAID_VERSION could not be resolved" >&2; exit 1; }
    info='{}'
  fi
  get() { printf '%s' "$info" | python3 -c "import json,sys; print(json.load(sys.stdin).get('$1', ''))"; }
  UNRAID_VERSION=$(get version); WEBGUI_LINE=$(get webgui_ref); WEBGUI_REF=$(get webgui_commit); RELEASED=$(get released)
fi
WEBGUI_REF=${WEBGUI_REF:-master}
WEBGUI_LINE=${WEBGUI_LINE:-$WEBGUI_REF}
git -C "$V/webgui" fetch -q --depth 1 origin "$WEBGUI_REF"
git -C "$V/webgui" checkout -q FETCH_HEAD

# what is tested – shown in the report and on the results page
python3 - "$V" "${UNRAID_VERSION:-}" "$WEBGUI_LINE" "$RELEASED" <<'PY'
import json, subprocess, sys
v, unraid, ref, released = sys.argv[1:5]
def head(d):
    return subprocess.run(['git', '-C', f'{v}/{d}', 'log', '-1', '--format=%h|%cs'], capture_output=True, text=True).stdout.strip().split('|') + ['', '']
info = {'unraid': unraid, 'released': released, 'webgui': {'ref': ref, 'commit': head('webgui')[0], 'date': head('webgui')[1]}}
for name, d in (('folderview3', 'folder.view3'), ('folderview2', 'folder.view2'), ('compose', 'compose_plugin')):
    c, date = head(d)[:2]
    info[name] = {'commit': c, 'date': date}
json.dump(info, open(f'{v}/versions.json', 'w'), indent=1)
print('testing against: Unraid ' + (unraid or '?') + (f' (released {released})' if released else '') + f" – webgui {ref} @ {info['webgui']['commit']}, "
      + ', '.join(f"{k} @ {info[k]['commit']}" for k in ('folderview3', 'folderview2', 'compose')))
PY

# --- fake web root ---
E=/usr/local/emhttp
mkdir -p $E/plugins $E/state/plugins/dynamix.docker.manager
# network state (Unraid 7.0's DockerClient.php reads it at load time)
printf '[eth0]\nBRNAME="br0"\nPROTOCOL:0="ipv4"\nIPADDR:0="192.168.1.10"\nNETMASK:0="255.255.255.0"\nGATEWAY="192.168.1.1"\nUSE_DHCP="no"\n' > $E/state/network.ini
ln -sfn "$V/webgui/emhttp/plugins/dynamix" $E/plugins/dynamix
ln -sfn "$V/webgui/emhttp/plugins/dynamix.docker.manager" $E/plugins/dynamix.docker.manager
ln -sfn "$V/webgui/emhttp/plugins/dynamix.vm.manager" $E/plugins/dynamix.vm.manager
ln -sfn $E/plugins/dynamix $E/webGui
ln -sfn "$V/folder.view3/src/folder.view3/usr/local/emhttp/plugins/folder.view3" $E/plugins/folder.view3
ln -sfn "$V/folder.view2/src/folder.view2/usr/local/emhttp/plugins/folder.view2" $E/plugins/folder.view2
ln -sfn "$V/compose_plugin/source/compose.manager" $E/plugins/compose.manager
ln -sfn "$REPO/src/swarm-view" $E/plugins/swarm-view

# --- fake flash config ---
C=/boot/config/plugins
mkdir -p /var/run $C/dynamix $C/swarm-view $C/folder.view3 $C/folder.view2 $C/compose.manager/projects
for p in media nextcloud-aio; do
  mkdir -p $C/compose.manager/projects/$p
  echo "$p" > $C/compose.manager/projects/$p/name
  printf 'services:\n  app:\n    image: alpine\n' > $C/compose.manager/projects/$p/docker-compose.yml
done
echo "Compose test project" > $C/compose.manager/projects/media/description
touch /var/run/dockerd.pid 2>/dev/null || true
echo "setup done: web root $E -> $V, Swarm View from $REPO"
