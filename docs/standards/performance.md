# Performance standards

Status: implemented as of Phase 3 (ADR-0017).

## In the code

- Every page has a query budget in `tests/Feature/Performance/QueryBudgetTest.php`.
  Raise one only on purpose, in the same reviewed change.
- No lazy loading: eager-load relations (`with`, `load`). It throws outside
  production and is logged in production.
- Lists are paginated; exports stream with `lazyById()` or a cursor, never
  `get()` on unbounded tables.
- Heavy work (PDF, file scanning, imports, exports, outbound calls) goes to a
  queue (Phase 4).
- New indexes come with the query they serve and an `EXPLAIN (ANALYZE,
  BUFFERS)` on realistic data in the pull request.

## Signals

| Signal                              | Where                                                 |
| ----------------------------------- | ----------------------------------------------------- |
| App vs database time per response   | `Server-Timing` header (not in production by default) |
| Slow statements                     | log, `Slow database statement.`, SQL text only        |
| Slow requests (total database time) | log, with the request id                              |
| Statement statistics                | `pg_stat_statements` (top statements per run)         |
| N+1 in production                   | log, `Lazy loading (N+1) detected.`                   |

## Measuring

See docs/operations/performance-testing.md. A claim needs the environment,
dataset, scenario, duration, tool and the raw output in docs/evidence.

## Acceptance (initial; set per application from its requirements)

| Metric                                | Initial target    |
| ------------------------------------- | ----------------- |
| Unexpected errors under expected load | < 0.1 %           |
| Server time (app + db) p95 for reads  | < 300 ms          |
| Server time p95 for writes            | < 1 s             |
| Query count per page                  | within its budget |

Client latency targets are set on the target host with the load generator
on another machine; the local laptop saturates first (evidence, Phase 3).
