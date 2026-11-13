#!/usr/bin/env bash
#
# Deploys one release artifact on this server (ADR-0032), as root:
#
#   sudo scripts/deploy/deploy.sh engineering-template-<sha>.tar.gz
#
# 1. verify the artifact (checksum, contents; scripts/release/verify.sh);
# 2. unpack it into $BASE/releases/<sha> (or reuse it if already there);
# 3. link the shared storage and .env, migrate, build the caches;
# 4. switch $BASE/current to it in one rename, reload PHP-FPM, restart
#    the queue workers;
# 5. smoke-test it through Nginx (scripts/deploy/smoke.sh): the release
#    answering must be this one. On failure, switch back to the previous
#    release, check it, and exit 1;
# 6. keep the newest $KEEP releases.
#
# Migrations run forward only and must leave the previous release working
# (expand, then contract in a later release): a rollback switches code, not
# the database.
#
# Settings (environment): APP (template), BASE (/srv/$APP), PHP_VERSION
# (8.5), SMOKE_HOST (the site's host name), KEEP (5), MIGRATE (1),
# RELOAD (systemctl reload php$PHP_VERSION-fpm).
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
ARCHIVE=$1
APP=${APP:-template}
BASE=${BASE:-/srv/$APP}
PHP=${PHP_VERSION:-8.5}
KEEP=${KEEP:-5}
MIGRATE=${MIGRATE:-1}
RELOAD=${RELOAD:-systemctl reload php$PHP-fpm}
: "${SMOKE_HOST:?SMOKE_HOST must name the site, e.g. app.example.org}"

[ "$(id -u)" = 0 ] || { echo "deploy.sh: run as root (sudo)" >&2; exit 1; }

log() { echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) $*" | tee -a "$BASE/deploys.log"; }
as_app() { runuser -u "$APP" -- "$@"; }
artisan() { (cd "$1" && as_app "/usr/bin/php$PHP" artisan "${@:2}" --no-interaction); }

switch_to() {
    ln -sfn "$1" "$BASE/current.next"
    mv -T "$BASE/current.next" "$BASE/current"
    $RELOAD
    artisan "$1" queue:restart > /dev/null
}

# 1. Verify.
bash "$HERE/../release/verify.sh" "$ARCHIVE"
FILE=$(basename "$ARCHIVE")
ROOT=${FILE%.tar.gz}
SHA=${ROOT##*-}
RELEASE=$BASE/releases/$SHA
PREVIOUS=$(readlink -e "$BASE/current" 2> /dev/null || true)

if [ "$PREVIOUS" = "$RELEASE" ]; then
    log "$SHA already live; nothing to do"
    exit 0
fi

# 2. Unpack (once per commit).
if [ ! -d "$RELEASE" ]; then
    STAGE=$(mktemp -d "$BASE/releases/.incoming-XXXXXX")
    tar -xzf "$ARCHIVE" -C "$STAGE"
    mv -T "$STAGE/$ROOT" "$RELEASE"
    rmdir "$STAGE"
fi

# 3. Shared state, owner, database, caches.
rm -rf "$RELEASE/storage"
ln -sfn "$BASE/shared/storage" "$RELEASE/storage"
ln -sfn "$BASE/shared/.env" "$RELEASE/.env"
chown -R "$APP:$APP" "$RELEASE"

if [ "$MIGRATE" = 1 ]; then
    artisan "$RELEASE" migrate --force
fi
for cache in config:cache route:cache view:cache event:cache; do
    artisan "$RELEASE" "$cache" > /dev/null
done

# 4. Switch.
log "$SHA switching (previous: ${PREVIOUS##*/})"
switch_to "$RELEASE"

# 5. Smoke test; back to the previous release if it fails.
if SMOKE_HOST=$SMOKE_HOST bash "$HERE/smoke.sh" "$SHA"; then
    log "$SHA live"
else
    if [ -n "$PREVIOUS" ] && [ -d "$PREVIOUS" ]; then
        log "$SHA failed its smoke test; rolling back to ${PREVIOUS##*/}"
        switch_to "$PREVIOUS"
        SMOKE_HOST=$SMOKE_HOST bash "$HERE/smoke.sh" "${PREVIOUS##*/}" && log "${PREVIOUS##*/} live again (rolled back)"
    else
        log "$SHA failed its smoke test; no previous release to return to"
    fi
    exit 1
fi

# 6. Keep the newest $KEEP releases, never the live or the previous one.
CURRENT=$(readlink -f "$BASE/current")
ls -1dt "$BASE"/releases/*/ 2> /dev/null | sed 's#/$##' | tail -n +"$((KEEP + 1))" | while read -r old; do
    if [ "$old" != "$CURRENT" ] && [ "$old" != "$PREVIOUS" ]; then
        rm -rf "$old"
        log "${old##*/} removed (keeping $KEEP)"
    fi
done
