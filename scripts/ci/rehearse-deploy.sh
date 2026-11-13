#!/usr/bin/env bash
#
# Rehearses deployment and rollback on a CI runner prepared by
# server-setup.sh (ADR-0032), with the real scripts and this commit's
# artifact (A). From A it derives:
#   B - the same code under another commit id: a second, distinct release;
#   C - a release that passes verify.sh but breaks once live (its main
#       script is missing), so its smoke test fails.
# Then, checking after each step which release answers:
#   deploy B; deploy A; roll back (to B); deploy A again (reused);
#   deploy C (fails, back to A by itself); deploy A (already live).
#
# CI runners only.
#
#   scripts/ci/rehearse-deploy.sh dist/engineering-template-<sha>.tar.gz
set -euo pipefail

if [ "${CI:-}" != "true" ] || [ "${GITHUB_ACTIONS:-}" != "true" ]; then
    echo "rehearse-deploy.sh runs on CI runners only." >&2
    exit 1
fi

# A failure names its line, with the application and web server logs, as
# annotations on the run.
failed() {
    echo "::error title=rehearse-deploy.sh line $1::$2"
    for log in /srv/template/deploys.log /srv/template/shared/storage/logs/laravel.log /var/log/nginx/error.log; do
        sudo test -f "$log" && sudo tail -n 8 "$log" | while IFS= read -r line; do echo "::error title=$(basename "$log")::$line"; done
    done
    return 0
}
trap 'failed "$LINENO" "$BASH_COMMAND"' ERR

A_ARCHIVE=$1
A=$(basename "$A_ARCHIVE" .tar.gz)
A=${A##*-}
B=$(printf 'b%.0s' {1..40})
C=$(printf 'c%.0s' {1..40})
HOST=${APP_HOST:-template.test}
WORK=$(mktemp -d)
OUT=$WORK/artifacts
mkdir -p "$OUT"
cp "$A_ARCHIVE" "$A_ARCHIVE.sha256" "$OUT/"

export SMOKE_HOST=$HOST RELOAD="systemctl reload php${PHP_VERSION:-8.5}-fpm"
deploy() { sudo --preserve-env=SMOKE_HOST,RELOAD bash scripts/deploy/deploy.sh "$OUT/engineering-template-$1.tar.gz"; }
rollback() { sudo --preserve-env=SMOKE_HOST,RELOAD bash scripts/deploy/rollback.sh "$@"; }
serving() {
    curl -s --insecure --resolve "$HOST:443:127.0.0.1" "https://$HOST/health/ready" | sed -n 's/.*"release":"\([0-9a-f]*\)".*/\1/p'
}
expect_serving() {
    local now
    now=$(serving)
    if [ "$now" != "$1" ]; then
        echo "::error title=Rehearsal::$2: serving ${now:0:12}, expected ${1:0:12}"
        exit 1
    fi
    echo "::notice title=Rehearsal::$2: serving ${1:0:12}"
}

# B and C from A.
relabel() {
    local id=$1 broken=$2 dir="$WORK/relabel-$1"
    mkdir -p "$dir"
    tar -xzf "$A_ARCHIVE" -C "$dir"
    mv "$dir/engineering-template-$A" "$dir/engineering-template-$id"
    sed -i "s/$A/$id/" "$dir/engineering-template-$id/RELEASE.json"
    if [ "$broken" = 1 ]; then
        rm "$dir"/engineering-template-"$id"/public/build/assets/app-*.js
    fi
    tar -czf "$OUT/engineering-template-$id.tar.gz" -C "$dir" "engineering-template-$id"
    (cd "$OUT" && sha256sum "engineering-template-$id.tar.gz" > "engineering-template-$id.tar.gz.sha256")
}
relabel "$B" 0
relabel "$C" 1

deploy "$B"
expect_serving "$B" "1. first release deployed"

deploy "$A"
expect_serving "$A" "2. new release deployed"

rollback
expect_serving "$B" "3. rolled back to the previous release"

deploy "$A"
expect_serving "$A" "4. new release deployed again (unpacked once)"

if deploy "$C"; then
    echo "::error title=Rehearsal::5. a broken release was accepted"
    exit 1
fi
expect_serving "$A" "5. broken release refused by its smoke test and rolled back"
sudo grep -q "$C failed its smoke test; rolling back to $A" /srv/template/deploys.log

deploy "$A"
expect_serving "$A" "6. deploying the live release again changes nothing"

echo "::group::deploys.log"
sudo cat /srv/template/deploys.log
echo "::endgroup::"
