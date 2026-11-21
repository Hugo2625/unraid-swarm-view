#!/bin/bash
# Swarm View test bench – setup.
# Builds a fake Unraid web root (/usr/local/emhttp) and /boot/config from the vendor sources,
# so the original Unraid, FolderView and Compose Manager pages run unchanged.
# Run as root in a throwaway container/VM – it writes to /usr/local/emhttp and /boot/config.
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
[ "${1:-}" = "--update" ] && for d in "$V"/*/; do git -C "$d" pull -q || true; done

# --- fake web root ---
E=/usr/local/emhttp
mkdir -p $E/plugins $E/state/plugins/dynamix.docker.manager
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
