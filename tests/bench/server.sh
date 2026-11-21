#!/bin/bash
# Starts the bench web server on http://127.0.0.1:${PORT:-8090}/Docker?s=<scenario>
BENCH="$(cd "$(dirname "$0")" && pwd)"
pkill -f "php -S 127.0.0.1:${PORT:-8090}" 2>/dev/null; sleep 0.3
exec php -d short_open_tag=1 -d display_errors=1 -S 127.0.0.1:${PORT:-8090} -t /usr/local/emhttp "$BENCH/server/router.php"
