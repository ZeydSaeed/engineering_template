#!/usr/bin/env bash
# Point-in-time recovery, rehearsed (docs/operations/recovery.md, ADR-0022).
# On a disposable machine with Docker (the CI runner), never on a shared
# database server: it starts its own PostgreSQL with WAL archiving, takes a
# base backup, writes, notes the time, then "makes a mistake" (deletes every
# row), and recovers a fresh server to the noted time from the base backup
# and the archived WAL. It passes when the recovered server has exactly the
# rows written before the noted time, and none of the mistake.
#
#   bash scripts/ci/pitr-rehearsal.sh
#
# The same steps on a real host use pgBackRest (repository, retention,
# encryption); this proves the mechanism and the recovery settings.
set -euo pipefail

IMAGE="postgres:18.2@sha256:a688ae5c99f86c1424e046ace0de890608cc0ddb5ec8ef72a6ff8305fdc8f4d2"
PASSWORD="pitr-rehearsal-only"
PREFIX="pitr-rehearsal-$$"

cleanup() {
  docker rm -f "$PREFIX-primary" "$PREFIX-restored" >/dev/null 2>&1 || true
  docker volume rm "$PREFIX-archive" "$PREFIX-base" >/dev/null 2>&1 || true
}
trap cleanup EXIT

sql() { # container, statement
  docker exec -i "$1" psql -U postgres -d rehearsal -v ON_ERROR_STOP=1 -Atc "$2"
}

wait_ready() { # container
  for _ in $(seq 1 60); do
    if docker exec "$1" pg_isready -U postgres -d rehearsal >/dev/null 2>&1; then return 0; fi
    sleep 1
  done
  docker logs "$1" | tail -30
  echo "::error::$1 did not become ready"
  exit 1
}

docker volume create "$PREFIX-archive" >/dev/null
docker volume create "$PREFIX-base" >/dev/null

# The primary archives every finished WAL segment to its own volume.
docker run -d --name "$PREFIX-primary" \
  -e POSTGRES_PASSWORD="$PASSWORD" -e POSTGRES_DB=rehearsal \
  -v "$PREFIX-archive:/archive" -v "$PREFIX-base:/base" \
  "$IMAGE" \
  -c wal_level=replica -c archive_mode=on \
  -c "archive_command=test ! -f /archive/%f && cp %p /archive/%f" >/dev/null
docker exec -u root "$PREFIX-primary" chown postgres:postgres /archive /base
wait_ready "$PREFIX-primary"

sql "$PREFIX-primary" "CREATE TABLE ledger (id bigint PRIMARY KEY, note text NOT NULL, at timestamptz NOT NULL DEFAULT now())"
sql "$PREFIX-primary" "INSERT INTO ledger (id, note) SELECT g, 'before the base backup' FROM generate_series(1, 1000) g"

started=$(date +%s)
docker exec -u postgres "$PREFIX-primary" pg_basebackup -D /base/data -X stream -c fast
echo "base backup taken in $(( $(date +%s) - started )) s"

sql "$PREFIX-primary" "INSERT INTO ledger (id, note) SELECT g, 'after the base backup' FROM generate_series(1001, 1500) g"
target=$(sql "$PREFIX-primary" "SELECT now()")
sleep 2

# The mistake, after the target time.
sql "$PREFIX-primary" "DELETE FROM ledger"
sql "$PREFIX-primary" "INSERT INTO ledger (id, note) VALUES (9999, 'written after the mistake')"
segment=$(sql "$PREFIX-primary" "SELECT pg_walfile_name(pg_switch_wal())")

# Wait until the segment holding the mistake is archived.
for _ in $(seq 1 60); do
  archived=$(sql "$PREFIX-primary" "SELECT coalesce(last_archived_wal, '') FROM pg_stat_archiver")
  if [[ -n "$archived" && ! "$archived" < "$segment" ]]; then break; fi
  sleep 1
done
echo "target time: $target; last archived segment: $archived (needed $segment)"
docker stop "$PREFIX-primary" >/dev/null

# A fresh server from the base backup, replaying the archive up to the target.
docker run --rm -v "$PREFIX-base:/base" --entrypoint bash "$IMAGE" -c "
  touch /base/data/recovery.signal
  cat >> /base/data/postgresql.auto.conf <<CONF
restore_command = 'cp /archive/%f %p'
recovery_target_time = '$target'
recovery_target_action = 'promote'
CONF
  chown -R postgres:postgres /base/data && chmod 700 /base/data"

started=$(date +%s)
docker run -d --name "$PREFIX-restored" \
  -e PGDATA=/base/data -e POSTGRES_PASSWORD="$PASSWORD" \
  -v "$PREFIX-archive:/archive:ro" -v "$PREFIX-base:/base" \
  "$IMAGE" >/dev/null
wait_ready "$PREFIX-restored"
for _ in $(seq 1 120); do
  [[ "$(sql "$PREFIX-restored" "SELECT pg_is_in_recovery()")" == "f" ]] && break
  sleep 1
done
recovered_in=$(( $(date +%s) - started ))

rows=$(sql "$PREFIX-restored" "SELECT count(*) FROM ledger")
after=$(sql "$PREFIX-restored" "SELECT count(*) FROM ledger WHERE note = 'after the base backup'")
mistake=$(sql "$PREFIX-restored" "SELECT count(*) FROM ledger WHERE id = 9999")
echo "recovered in ${recovered_in} s: ${rows} rows (${after} written after the base backup), ${mistake} written after the mistake"

if [[ "$rows" != "1500" || "$after" != "500" || "$mistake" != "0" ]]; then
  docker logs "$PREFIX-restored" | tail -40
  echo "::error::PITR rehearsal FAILED: expected 1500 rows, 500 after the base backup and none after the mistake"
  exit 1
fi
echo "::notice::PITR rehearsal PASSED: recovered to $target in ${recovered_in} s, 1500 rows, the deletion undone"
