#!/usr/bin/env bash
#
# Returns this server to an earlier release (ADR-0032), as root:
#
#   sudo SMOKE_HOST=app.example.org scripts/deploy/rollback.sh          # the one before
#   sudo SMOKE_HOST=app.example.org scripts/deploy/rollback.sh <sha>    # a given one
#
# The code only: migrations stay (they are written so the previous release
# keeps working). The release must still be on the server (deploy.sh keeps
# the newest five); otherwise deploy its artifact again.
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
APP=${APP:-template}
BASE=${BASE:-/srv/$APP}
PHP=${PHP_VERSION:-8.5}
RELOAD=${RELOAD:-systemctl reload php$PHP-fpm}
: "${SMOKE_HOST:?SMOKE_HOST must name the site}"

[ "$(id -u)" = 0 ] || { echo "rollback.sh: run as root (sudo)" >&2; exit 1; }

log() { echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) $*" | tee -a "$BASE/deploys.log"; }

CURRENT=$(readlink -f "$BASE/current")

if [ $# -gt 0 ]; then
    TARGET=$BASE/releases/$1
else
    # The release that was live before the current one, from the log.
    TARGET=$(grep ' live' "$BASE/deploys.log" | awk '{print $2}' | grep -v -x "${CURRENT##*/}" | tail -n 1 || true)
    [ -n "$TARGET" ] || { echo "rollback.sh: no earlier live release in $BASE/deploys.log" >&2; exit 1; }
    TARGET=$BASE/releases/$TARGET
fi

[ -d "$TARGET" ] || { echo "rollback.sh: ${TARGET##*/} is not on this server; deploy its artifact" >&2; exit 1; }
[ "$TARGET" != "$CURRENT" ] || { echo "rollback.sh: ${TARGET##*/} is already live" >&2; exit 1; }

log "${TARGET##*/} rolling back (from ${CURRENT##*/})"
ln -sfn "$TARGET" "$BASE/current.next"
mv -T "$BASE/current.next" "$BASE/current"
$RELOAD
(cd "$TARGET" && runuser -u "$APP" -- "/usr/bin/php$PHP" artisan queue:restart --no-interaction > /dev/null)

SMOKE_HOST=$SMOKE_HOST bash "$HERE/smoke.sh" "${TARGET##*/}"
log "${TARGET##*/} live (rollback)"
