# Phase 3 measurements (2026-11-11)

**Environment, for every number below.** One laptop: Intel Core i5-7300U
(2 cores, 4 threads), 15.8 GB RAM, Windows 10. Laravel Herd serves the
application (Nginx and php-cgi, about 10 workers, PHP 8.5.12 with OPcache)
with configuration, routes, views and events cached. PostgreSQL 18.2 runs on
the same machine and also holds the owner's other local databases. k6 2.2.0
runs on the same machine. Dataset: 1,000 synthetic users with their own
authenticator secrets and 50,000 audit records. These are local
measurements; they are not the capacity of a Linux server and must be
repeated on the target host before any capacity decision.

## Load runs (tests/load/baseline.js, scripts/perf/run-baseline.ps1)

Visitors load the home and sign-in pages; staff sign in for real (password
and TOTP), then move between three pages with 2 to 5 s think time. Ramp 30 s,
hold, ramp down 15 s.

| Run        | VUs (visitors + staff) | Hold  | Requests | Req/s | Failed | Checks      | k6 latency p50 / p95 | app time p50 / p95 | db time p50 / p95 | Machine CPU         |
| ---------- | ---------------------- | ----- | -------- | ----- | ------ | ----------- | -------------------- | ------------------ | ----------------- | ------------------- |
| smoke      | 2 + 2                  | 30 s  | 124      | 1.4   | 0 %    | 89/89       | 146 ms / 328 ms      | 27 ms / 89 ms      | 81 ms / 208 ms    | not sampled         |
| normal-50  | 30 + 20                | 2 min | 1,472    | 8.4   | 0 %    | 1,146/1,146 | 2.62 s / 6.83 s      | 36 ms / 150 ms     | 251 ms / 717 ms   | not sampled         |
| normal-100 | 60 + 40                | 2 min | 1,803    | 9.9   | 0 %    | 1,399/1,399 | 7.39 s / 9.28 s      | 34 ms / 115 ms     | 209 ms / 456 ms   | avg 93 %, p90 100 % |

App and db time come from the Server-Timing header (time inside Laravel);
k6 latency is the whole round trip seen by the client.

### Findings

1. **Saturation between 50 and 100 VUs on this machine.** Throughput stayed
   near 9 to 10 req/s while client latency tripled and server-side time did
   not grow: requests queued before PHP started them, with the CPU at 93 %
   on average (k6, PHP, Nginx and PostgreSQL share four threads).
2. **Connection set-up dominates database time.** pg_stat_statements shows
   the statements themselves are cheap (session read 0.05 ms, session
   update 0.14 ms, `set time zone` 1.1 ms on average), yet Laravel measures
   51 to 250 ms of database time per request. `set time zone 'UTC'` runs once
   per request, so every request opens a new connection; on Windows each one
   starts a server process and authenticates. A pooler (PgBouncer) or
   persistent connections must be measured on the target host (ADR-0017).
3. **One second-factor failure in 40 sign-ins** under saturation (audit:
   `auth.two_factor_failed`, user 40; the retry 5 s later succeeded). Cause
   NOT determined. Candidates: Fortify keys its used-code store by the code
   only (`fortify.2fa_codes.md5(code)`), so two users entering the same code
   in one window collide; or queueing delay. See the phase report.
4. **Per-process CPU sampling is unreliable here**: Herd recycles php-cgi
   processes and PostgreSQL backends live per connection, so their CPU time
   leaves with them. Total machine CPU is the reliable signal.
5. Working sets during the 100-VU run: php-cgi 10 processes, 302 MB in
   total (about 30 MB each); PostgreSQL 18 processes, 266 MB (all local
   databases); Nginx 49 MB; k6 75 MB.

## Audit trail (scripts/bench/audit-writes.ps1)

| Concurrent writers | Writes | Writes/s | p50 per write | worst p95 |
| ------------------ | ------ | -------- | ------------- | --------- |
| 1                  | 250    | 153      | 3.2 ms        | 6.3 ms    |
| 4                  | 1,000  | 257      | 9.8 ms        | 14.8 ms   |
| 8                  | 2,000  | 232      | 21.6 ms       | 39 ms     |

The advisory lock serialises writers: throughput levels off at about 230 to
260 writes/s and latency grows with concurrency. The chain was intact after
the runs (53,505 records). Verifying 50,000 records took 8.5 s
(about 0.17 ms per record).

## Defects found during the phase

- The per-request database-time warning also fired in console commands and
  queue workers, where one connection serves many units of work; now HTTP
  only.
- Concurrent first compilation of a Blade view failed on Windows with
  "Access is denied" on rename (a 500 in the first smoke run). Linux renames
  atomically; the run script now warms every page up once before the load.
