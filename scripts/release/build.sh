#!/usr/bin/env bash
#
# Builds the release artifact for the checked-out commit (ADR-0031): one
# archive, built once, deployed unchanged to every environment.
#
#   scripts/release/build.sh [output-dir]       (default: dist)
#
# Output, named after the full commit SHA:
#   engineering-template-<sha>.tar.gz           the release
#   engineering-template-<sha>.tar.gz.sha256    its SHA-256 (sha256sum format)
#
# The release is built from `git archive` of the commit, never from the
# working tree, so nothing uncommitted or local (.env, .tools, keys) can
# reach it. Production dependencies only; assets built; no tests, sources
# of the assets or development configuration. Times, owners and order in
# the archive are fixed, so the same commit gives the same bytes.
set -euo pipefail

OUT=${1:-dist}
COMPOSER=${COMPOSER_BIN:-composer}
NAME=engineering-template

if ! git diff --quiet HEAD -- || ! git diff --cached --quiet; then
    echo "build.sh: commit or stash your changes first; a release is built from a commit." >&2
    exit 1
fi

SHA=$(git rev-parse HEAD)
EPOCH=$(git log -1 --format=%ct HEAD)
WORK=$(mktemp -d)
RELEASE="$WORK/$NAME-$SHA"
trap 'rm -rf "$WORK"' EXIT

mkdir -p "$RELEASE" "$OUT"
git archive --format=tar HEAD | tar -x -C "$RELEASE"

(
    cd "$RELEASE"

    $COMPOSER install --no-dev --no-interaction --prefer-dist --no-progress \
        --optimize-autoloader --quiet

    # The asset build runs artisan (route helpers), which needs a key; the
    # file is removed before packing.
    cp .env.example .env
    php artisan key:generate --quiet
    npm ci --no-audit --no-fund --loglevel=error
    npm run build --silent > /dev/null
    rm -f .env

    # Only what runs on a server.
    rm -rf node_modules tests .github .zap docs scripts resources/js \
        resources/css .gitleaksignore .editorconfig .gitattributes \
        .prettierrc .prettierignore phpunit.xml phpunit.pgsql.xml \
        playwright.config.ts vite.config.ts tsconfig.json eslint.config.js \
        deptrac.yaml phpstan.neon pint.json package.json package-lock.json \
        components.json CLAUDE.md .npmrc pnpm-workspace.yaml .gitignore deploy
    rm -rf bootstrap/cache/*.php storage/framework/views/*.php

    # What this release is (commit and its time), read by deploy.
    cat > RELEASE.json <<JSON
{
    "name": "$NAME",
    "commit": "$SHA",
    "committed_at": "$(date -u -d "@$EPOCH" +%Y-%m-%dT%H:%M:%SZ 2> /dev/null || date -u -r "$EPOCH" +%Y-%m-%dT%H:%M:%SZ)"
}
JSON
)

ARCHIVE="$NAME-$SHA.tar.gz"
tar --sort=name --mtime="@$EPOCH" --owner=0 --group=0 --numeric-owner \
    --pax-option=exthdr.name=%d/PaxHeaders/%f,delete=atime,delete=ctime \
    -cf - -C "$WORK" "$NAME-$SHA" | gzip -n -9 > "$OUT/$ARCHIVE"

(cd "$OUT" && sha256sum "$ARCHIVE" > "$ARCHIVE.sha256")
echo "$OUT/$ARCHIVE"
cat "$OUT/$ARCHIVE.sha256"
