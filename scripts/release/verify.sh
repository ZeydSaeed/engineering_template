#!/usr/bin/env bash
#
# Checks a release artifact before it is used (ADR-0031): its SHA-256
# matches, it unpacks into one directory named after its commit, it has
# what a server runs, and nothing that must never ship.
#
#   scripts/release/verify.sh dist/engineering-template-<sha>.tar.gz
set -euo pipefail

ARCHIVE=$1
DIR=$(dirname "$ARCHIVE")
FILE=$(basename "$ARCHIVE")
ROOT=${FILE%.tar.gz}
SHA=${ROOT##*-}

fail() {
    echo "verify.sh: $*" >&2
    exit 1
}

[[ $SHA =~ ^[0-9a-f]{40}$ ]] || fail "$FILE is not named after a full commit SHA"
(cd "$DIR" && sha256sum --check --strict --quiet "$FILE.sha256") || fail "checksum does not match"

LIST=$(tar -tzf "$ARCHIVE")

# One top directory, named after the commit.
if grep -v -q "^$ROOT/" <<< "$LIST"; then
    fail "entries outside $ROOT/"
fi

for required in artisan RELEASE.json composer.lock vendor/autoload.php \
    public/index.php public/build/manifest.json bootstrap/app.php \
    resources/views/app.blade.php lang/ar.json; do
    grep -q -x "$ROOT/$required" <<< "$LIST" || fail "missing $required"
done

# Never in a release: secrets, local state, development tools and sources.
forbidden='(^|/)\.env$|(^|/)\.git/|(^|/)node_modules/|^[^/]+/tests/|(^|/)\.tools/|\.sqlite$|(^|/)auth\.json$|^[^/]+/storage/logs/.+\.log$|^[^/]+/vendor/(phpunit/phpunit|pestphp|larastan|phpstan/phpstan|mockery|fakerphp|deptrac|laravel/(pint|pail|sail|pao)|nunomaduro/collision)/'
if hits=$(grep -E "$forbidden" <<< "$LIST"); then
    fail "forbidden entries: $(head -n 5 <<< "$hits" | tr '\n' ' ')"
fi

# Keys and certificates of our own (vendor ships public CA bundles).
if hits=$(grep -E '\.(pem|key|p12|pfx)$' <<< "$LIST" | grep -v "^$ROOT/vendor/"); then
    fail "key or certificate files: $(head -n 5 <<< "$hits" | tr '\n' ' ')"
fi

COMMIT=$(tar -xzOf "$ARCHIVE" "$ROOT/RELEASE.json" | sed -n 's/.*"commit": "\([0-9a-f]*\)".*/\1/p')
[ "$COMMIT" = "$SHA" ] || fail "RELEASE.json names $COMMIT, not $SHA"

echo "verify.sh: $FILE ok ($(wc -l <<< "$LIST") entries)"
