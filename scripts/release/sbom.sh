#!/usr/bin/env bash
#
# The software bill of materials of a release artifact (ADR-0031): every
# Composer and npm package the release carries, in CycloneDX JSON, made by
# Syft from the artifact itself, not from the working tree.
#
#   scripts/release/sbom.sh dist/engineering-template-<sha>.tar.gz
#   -> dist/engineering-template-<sha>.cdx.json
#
# SYFT_BIN names the Syft binary (default: syft; locally
# .tools/syft/syft.exe, version and checksum as in security.yml).
set -euo pipefail

ARCHIVE=$1
SYFT=${SYFT_BIN:-syft}
FILE=$(basename "$ARCHIVE")
ROOT=${FILE%.tar.gz}
OUT="$(dirname "$ARCHIVE")/$ROOT.cdx.json"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

tar -xzf "$ARCHIVE" -C "$WORK"

# The npm packages are built into public/build and not shipped; the lock
# file records them for the bill of materials.
git show "${ROOT##*-}:package-lock.json" > "$WORK/$ROOT/package-lock.json"

# A native Windows Syft (local runs in Git Bash) needs Windows paths.
native() { if command -v cygpath > /dev/null; then cygpath -m "$1"; else echo "$1"; fi; }

SYFT_CHECK_FOR_APP_UPDATE=false "$SYFT" scan "dir:$(native "$WORK/$ROOT")" \
    --source-name "$ROOT" --quiet -o "cyclonedx-json=$(native "$OUT")"

COUNT=$(grep -o '"bom-ref"' "$OUT" | wc -l)
[ "$COUNT" -gt 50 ] || { echo "sbom.sh: only $COUNT components; expected the Composer and npm packages" >&2; exit 1; }
echo "$OUT ($COUNT components)"
