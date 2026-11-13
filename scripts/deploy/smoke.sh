#!/usr/bin/env bash
#
# Smoke test of the release answering on this server (ADR-0032), through
# Nginx and PHP-FPM, as visitors reach it:
#
#   SMOKE_HOST=app.example.org scripts/deploy/smoke.sh <expected commit sha>
#
# - /health/ready is ready and names the expected release;
# - /up answers;
# - the sign-in page answers with its security headers;
# - the page's main script (from this release's build) is served.
#
# It connects to 127.0.0.1 with the site's name (SNI and Host), so it tests
# this machine whatever DNS says. The origin certificate is not publicly
# trusted (Cloudflare origin certificate), hence --insecure: the connection
# never leaves the machine.
set -euo pipefail

EXPECTED=$1
: "${SMOKE_HOST:?SMOKE_HOST must name the site}"
URL="https://$SMOKE_HOST"
CURL=(curl --silent --show-error --insecure --max-time 10 --resolve "$SMOKE_HOST:443:127.0.0.1")

fail() {
    echo "smoke.sh: $*" >&2
    exit 1
}

# Readiness, retried briefly while PHP-FPM finishes reloading.
ready=''
for _ in $(seq 1 10); do
    ready=$("${CURL[@]}" "$URL/health/ready" || true)
    grep -q "\"release\":\"$EXPECTED\"" <<< "$ready" && grep -q '"status":"ok"' <<< "$ready" && break
    sleep 1
done
grep -q '"status":"ok"' <<< "$ready" || fail "not ready: $ready"
grep -q "\"release\":\"$EXPECTED\"" <<< "$ready" || fail "serving another release: $ready"

[ "$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$URL/up")" = 200 ] || fail "/up does not answer 200"

HEADERS=$(mktemp)
trap 'rm -f "$HEADERS"' EXIT
PAGE=$("${CURL[@]}" -D "$HEADERS" "$URL/login")
grep -q '^HTTP/[0-9.]* 200' "$HEADERS" || fail "/login: $(head -n 1 "$HEADERS")"
for header in content-security-policy strict-transport-security x-frame-options x-content-type-options; do
    grep -qi "^$header:" "$HEADERS" || fail "/login without $header"
done

SCRIPT=$(grep -o '/build/assets/app-[A-Za-z0-9_-]*\.js' <<< "$PAGE" | head -n 1)
[ -n "$SCRIPT" ] || fail "/login names no application script"
[ "$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$URL$SCRIPT")" = 200 ] || fail "$SCRIPT is not served"

echo "smoke.sh: ${EXPECTED:0:12} ok on $SMOKE_HOST"
