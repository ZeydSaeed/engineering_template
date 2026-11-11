# ADR-0017: Measure before optimising; built-in performance signals

- Status: Accepted
- Date: 2026-11-11

## Context

Performance claims without measurements mislead. The first local runs
(docs/evidence/phase-3/SUMMARY.md) showed that most database time was
connection set-up, not SQL, which no amount of query tuning would have fixed.

## Problem

Make the split between application, database, connection and network time
visible in every environment, and keep regressions out of the code.

## Options

1. External APM only.
2. Signals built into the platform, plus load-test tooling in the repository.

## Decision

Option 2.

- `Server-Timing` on every response (`app`, `db`, statement count); off in
  production unless `PLATFORM_SERVER_TIMING=true`.
- Slow statements logged with SQL text only (never bound values); requests
  whose total database time passes a limit logged with their request id
  (HTTP only).
- N+1 lazy loading throws outside production and is logged in production.
- Query budgets per page as tests (`QueryBudgetTest`), set from measured
  counts.
- `pg_stat_statements` on every PostgreSQL server; the owner role may read
  and reset it.
- Load tests: k6 scenarios with real sign-in (TOTP), a local-host guard,
  resource sampling and statement statistics per run; every result recorded
  with its environment, dataset, duration and tool.
- Connection pooling (PgBouncer, transaction mode) or persistent connections
  are evaluated on the target Linux host, where connection cost differs
  from Windows; the decision follows the measurement.

## Consequences

A performance change must show before/after numbers from the same
environment and scenario.

## Security implications

Timings can help probe a system, hence off in production by default. Logged
SQL never includes values.

## Performance implications

One listener per statement and one header per response; negligible.

## Reversal or migration strategy

Each signal is a setting.
