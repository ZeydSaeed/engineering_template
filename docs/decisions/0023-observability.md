# ADR-0023: Health probes, application metrics and actionable alerts

- Status: Accepted
- Date: 2026-11-12

## Context

Outages announced by users, backups that silently stopped and stuck queues
are found late without monitoring; alerts nobody knows how to act on are
ignored.

## Problem

Expose the application's state to standard tools and make every alert
actionable.

## Options

1. Logs only.
2. A hosted monitoring service.
3. Prometheus-format metrics from the application plus standard exporters,
   with alert rules and runbooks in the repository.

## Decision

Option 3; a hosted service can scrape the same endpoint later.

- `/up` (liveness) and `/health/ready` (database, cache, files disk, queue):
  200 `{"status":"ok"}` or 503 `{"status":"fail"}`, never which part;
  `php artisan platform:health` shows the part.
- `/metrics`: queue sizes and oldest job age per queue (database and Redis
  drivers), failed jobs, outbox per status and oldest due, files per status
  and oldest pending, last successful backup and last run result per kind,
  audit chain result and time of last verification. Served to 127.0.0.1 or
  to a bearer token (`METRICS_TOKEN`, constant-time compare); 404 otherwise.
  Every series is always present, because Prometheus never alerts on a
  series that does not exist.
- `deploy/monitoring/alerts.yml`: application, data and infrastructure
  alerts (node, PostgreSQL, Redis and PHP-FPM exporters). Each has a
  severity, a summary and a runbook section with Meaning, Check, Act and
  Verify (`docs/runbooks`). `AlertRulesTest` enforces this and that every
  `platform_*` metric used exists.
- Probes and metrics are local-only at the web server too.

## Consequences

A new module adds its metrics to PlatformMetrics and its alerts with
runbook sections in the same change.

## Security implications

No dependency details or metrics are public.

## Performance implications

A scrape runs a dozen indexed counts; at 30 s intervals, negligible.

## Reversal or migration strategy

The endpoint format is the standard; any Prometheus-compatible service can
replace the self-hosted stack.
