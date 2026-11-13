# Performance testing (local)

Load tests never run against a shared or production host. `tests/load/lib/guard.js`
refuses any base URL but `*.test`, `localhost` and `127.0.0.1`.

## One-time setup

1. PostgreSQL with `pg_stat_statements` in `shared_preload_libraries`
   (needs a service restart) and the extension created by
   `scripts/db/create-local-databases.ps1`.
2. The workspace linked in Laravel Herd on PHP 8.5:
   `herd link engineering-template` and `herd isolate 8.5` (Herd restarts its
   Nginx), `APP_URL=http://engineering-template.test` in `.env`.
3. Synthetic data (local environment and development database only):
   `php artisan db:seed --class=PerformanceSeeder` with `PERF_USERS` and
   `PERF_AUDIT_EVENTS`. It writes `storage/app/private/perf/users.csv`
   (e-mail and authenticator secret per user; not committed).
4. `k6 run tests/load/selftest.js` (TOTP against the RFC 6238 vectors).

## A run

```powershell
. .\scripts\env.ps1
php artisan optimize
.\scripts\perf\run-baseline.ps1 -Name normal-50 -VisitorVus 30 -StaffVus 20 -Hold 2m
php artisan optimize:clear
```

The script clears rate limits and statement statistics, warms every page up
once, runs k6 while sampling CPU and memory every 2 s, and saves `run.txt`,
`k6.log`, `summary.json`, `resources.csv` and `top-statements.txt` under
`docs/evidence/<phase>/<date>-<name>/` (`-Phase`, `phase-3` by default).

The Requests module (ADR-0033): requesters draft and submit, approvers
decide. Seed approvers and requests once (local and development database
only), then run with `-Script requests`:

```powershell
php artisan db:seed --class=RequestsPerformanceSeeder   # PERF_REQUESTS, default 10000
.\scripts\perf\run-baseline.ps1 -Script requests -Phase phase-9 -Name requests-25 -StaffVus 20 -ApproverVus 5 -Hold 2m
```

Both scripts keep each VU's session between iterations (`noCookiesReset`)
and fail any staff page that ends on the sign-in page: without that, k6
signs staff out after their first round and measures redirects (the
Phase 3 correction in `docs/evidence/phase-3/SUMMARY.md`).

Audit write throughput: `.\scripts\bench\audit-writes.ps1 -Writes 250`.

## Reading the results

- k6 latency minus Server-Timing (app + db) is time outside Laravel:
  queueing for a worker, the web server, the network.
- Server-Timing db time minus pg_stat_statements time is connection and
  driver overhead.
- Total machine CPU is the reliable saturation signal on Windows; short-lived
  processes take their CPU time with them.
- The load generator shares the machine: a plateau here is the laptop's, not
  the application's.
