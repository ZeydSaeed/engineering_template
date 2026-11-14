# Capacity model and memory budget

Status: method implemented; numbers below are either MEASURED (with their
source) or ESTIMATE. Re-measure on the target host before sizing.

## PHP worker count

```
fpm_children = floor( (RAM − OS − PostgreSQL − Redis − antivirus − queue workers − 15 % margin)
                      / RSS_p95 of one PHP worker )

and   fpm_children + queue workers + scheduler + margin  ≤  connection limit of the runtime role
```

Then check CPU: if the machine's CPU is near 100 % before the workers are
busy, more workers only add queueing (MEASURED locally, Phase 9: 50 VUs
with staff really signed in gave 5.2 req/s, k6 p50 5.83 s, CPU 89 % on
average; docs/evidence/phase-9. The Phase 3 figure of 9 to 10 req/s
measured mostly redirects to the sign-in page: see the correction in
docs/evidence/phase-3/SUMMARY.md). On Linux (a 4-vCPU CI runner,
PostgreSQL 18, the deploy/ templates; docs/evidence/phase-10/followups.txt)
the same scenarios answer in p50 8 to 25 ms, and PgBouncer halves database
time per request (12 to 6 ms).

## Inputs

| Input                              | Value                                                                                | Source                                           |
| ---------------------------------- | ------------------------------------------------------------------------------------ | ------------------------------------------------ |
| PHP worker working set             | about 30 MB (302 MB / 10 php-cgi)                                                    | MEASURED, Windows php-cgi, docs/evidence/phase-3 |
| PostgreSQL backend                 | about 15 MB each (266 MB / 18, all local databases)                                  | MEASURED, Windows                                |
| Server time per request (app + db) | p50 about 0.4 s under load (app 0.11 s, db 0.27 to 0.30 s), mostly connection set-up | MEASURED, Windows, docs/evidence/phase-9         |
| SQL time per request               | under 2 ms for the template's pages                                                  | MEASURED, pg_stat_statements                     |
| Audit write ceiling                | about 230 to 260 writes/s (serialised)                                               | MEASURED                                         |
| Audit chain verification           | about 0.17 ms per record (1 M records ≈ 3 min)                                       | MEASURED                                         |
| Runtime role connection limit      | 40                                                                                   | CONFIGURED (ADR-0012)                            |
| Linux PHP-FPM worker RSS           | typically 40 to 80 MB                                                                | ESTIMATE, to measure                             |
| ClamAV daemon                      | about 1 to 1.5 GB                                                                    | ESTIMATE, to measure when a module scans files   |

## Example budget: 4 vCPU, 8 GB Linux host (ESTIMATE)

| Service                                                             | Budget                                     |
| ------------------------------------------------------------------- | ------------------------------------------ |
| OS, Nginx, monitoring agents                                        | 0.8 GB                                     |
| PostgreSQL (`shared_buffers` ≈ 1.5 GB, plus work_mem × connections) | 2.5 GB                                     |
| Redis (`maxmemory`)                                                 | 0.25 GB                                    |
| Queue workers (4 × 128 to 256 MB)                                   | 0.8 GB                                     |
| ClamAV (only if used)                                               | 1.2 GB                                     |
| Margin (15 %)                                                       | 1.2 GB                                     |
| PHP-FPM                                                             | rest ≈ 1.25 GB → about 16 workers at 75 MB |

## Connection set-up

Every request opened a new PostgreSQL connection, and that set-up was most
of the measured database time on Windows. On the target host, measure
`Server-Timing` db time against pg_stat_statements; if set-up dominates,
add PgBouncer in transaction mode (one pool per application role) and
re-measure.

## Next measurements (target host, load generator on another machine)

1. PHP-FPM RSS p95 per worker after warm-up and after `pm.max_requests`.
2. db time vs SQL time, with and without PgBouncer.
3. Saturation point (throughput plateau) and CPU per request.
4. Memory with antivirus and queue workers running.
