#!/usr/bin/env bash
# Load on a Linux server built from the deploy/ templates (Phase 10
# follow-ups): the release deployed with scripts/deploy/deploy.sh on a CI
# runner prepared by server-setup.sh with PostgreSQL 18 (APP_DB=pgsql),
# with or without PgBouncer (POOLER). Seeds synthetic users and requests,
# then runs the two k6 scenarios and prints their figures as annotations.
#
# For disposable CI runners only; the load generator shares the runner
# (4 vCPU), so the figures compare the two set-ups, they are not a
# capacity.
#
#   APP_DB=pgsql POOLER=pgbouncer scripts/ci/server-setup.sh
#   sudo ... scripts/deploy/deploy.sh dist/engineering-template-<sha>.tar.gz
#   scripts/ci/load-measure.sh <label>
set -euo pipefail

if [ "${CI:-}" != "true" ] || [ "${GITHUB_ACTIONS:-}" != "true" ]; then
    echo "load-measure.sh loads the machine it runs on; CI runners only." >&2
    exit 1
fi

LABEL=${1:?a label, e.g. direct or pgbouncer}
K6_IMAGE="grafana/k6:2.2.0@sha256:9bd01d6941fca969cb61bb57d2da5ee9b385fe2aa8881df3798c196564d6ace6"
APP=template
CURRENT=/srv/$APP/current
PHP_CLI=/usr/bin/php${PHP_VERSION:-8.5}
OUT="$RUNNER_TEMP/load-$LABEL"
mkdir -p "$OUT"

# Synthetic data, as for the local runs (docs/operations/performance-testing.md):
# the seeders refuse anything but the local environment, so the seeding runs
# under APP_ENV=local (real environment variables win over .env).
seed() {
    (cd "$CURRENT" && sudo -u "$APP" env APP_ENV=local PERF_DATABASE="$APP" PERF_USERS=300 \
        PERF_AUDIT_EVENTS=20000 PERF_REQUESTS=10000 "$PHP_CLI" artisan "$@" --no-interaction)
}
seed config:clear > /dev/null
seed db:seed --class=PerformanceSeeder --force
seed db:seed --class=RequestsPerformanceSeeder --force
(cd "$CURRENT" && sudo -u "$APP" "$PHP_CLI" artisan config:cache > /dev/null)
sudo install -m 0644 "/srv/$APP/shared/storage/app/private/perf/users.csv" "$OUT/users.csv"

k6() { # script, extra -e arguments...
    local script=$1
    shift
    docker run --rm --network host \
        -e K6_INSECURE_SKIP_TLS_VERIFY=true \
        -v "$PWD/tests/load:/scripts:ro" -v "$OUT:/out" \
        "$K6_IMAGE" run --quiet --summary-export "/out/$script.json" \
        -e BASE_URL=https://template.test -e USERS_CSV=/out/users.csv "$@" "/scripts/$script.js" \
        > "$OUT/$script.log" 2>&1 || {
        tail -40 "$OUT/$script.log"
        echo "::error title=k6 $script ($LABEL)::thresholds crossed or run failed"
        return 1
    }
}

# Every page once, so views and caches are warm.
k6 requests --vus 1 --iterations 1 -e STAFF_VUS=1
k6 baseline -e VISITOR_VUS=30 -e STAFF_VUS=20 -e HOLD=2m
k6 requests -e STAFF_VUS=20 -e APPROVER_VUS=5 -e HOLD=2m

# The figures, as annotations readable on the run's page.
python3 - "$OUT" "$LABEL" <<'PY'
import json, sys
out, label = sys.argv[1], sys.argv[2]
for script in ("baseline", "requests"):
    m = json.load(open(f"{out}/{script}.json"))["metrics"]
    def get(name, key):
        return m.get(name, {}).get(key, 0)
    line = (
        f"{script} ({label}): {int(get('http_reqs', 'count'))} requests, "
        f"{get('http_reqs', 'rate'):.1f} req/s, failed {get('http_req_failed', 'value') * 100:.2f} %, "
        f"checks {get('checks', 'passes')}/{get('checks', 'passes') + get('checks', 'fails')}; "
        f"latency p50 {get('http_req_duration', 'med'):.0f} ms p95 {get('http_req_duration', 'p(95)'):.0f} ms; "
        f"app p50 {get('server_app_ms', 'med'):.0f} ms; db p50 {get('server_db_ms', 'med'):.0f} ms p95 {get('server_db_ms', 'p(95)'):.0f} ms"
    )
    print(line)
    print(f"::notice title=load {script} {label}::{line}")
PY
