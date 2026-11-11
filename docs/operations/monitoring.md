# Monitoring

Decision: ADR-0023. Status: endpoints, metrics, alert rules and runbooks
implemented and tested locally. The Prometheus/Alertmanager stack itself and
the exporters run on the target host: Planned, NOT TESTED.

## Endpoints

| Path            | For                                            | Answer                                                                     |
| --------------- | ---------------------------------------------- | -------------------------------------------------------------------------- |
| `/up`           | liveness (the process serves)                  | 200                                                                        |
| `/health/ready` | readiness (database, cache, files disk, queue) | 200 `{"status":"ok"}` / 503 `{"status":"fail"}`                            |
| `/metrics`      | Prometheus                                     | text format; 127.0.0.1 or `Authorization: Bearer $METRICS_TOKEN`, else 404 |

All three are limited to the machine itself in `deploy/nginx/app.conf`.
`php artisan platform:health` prints which check failed.

## Application metrics

| Metric                                                 | Labels | Meaning                                                    |
| ------------------------------------------------------ | ------ | ---------------------------------------------------------- |
| `platform_up`                                          |        | the application answered                                   |
| `platform_queue_jobs`                                  | queue  | jobs waiting                                               |
| `platform_queue_oldest_job_age_seconds`                | queue  | age of the oldest waiting job (database and Redis drivers) |
| `platform_failed_jobs`                                 |        | rows in failed_jobs                                        |
| `platform_outbox_messages`                             | status | outbox messages per status                                 |
| `platform_outbox_oldest_pending_age_seconds`           |        | how long the oldest due message has waited                 |
| `platform_files`                                       | status | stored files per scan status                               |
| `platform_files_oldest_pending_age_seconds`            |        | oldest file waiting for a scan                             |
| `platform_backup_last_success_timestamp_seconds`       | kind   | last successful backup                                     |
| `platform_backup_last_run_ok`                          | kind   | 1 if the last run succeeded                                |
| `platform_audit_chain_ok`                              |        | 1 if the last verification passed                          |
| `platform_audit_chain_last_verified_timestamp_seconds` |        | when it last ran                                           |

## Stack on the target host

- Prometheus with `deploy/monitoring/prometheus.yml` and `alerts.yml`
  (all targets on 127.0.0.1).
- Exporters: node_exporter, postgres_exporter (a monitoring role with
  `pg_monitor`), redis_exporter, php-fpm_exporter (pool status page).
- Alertmanager routing `critical` to a phone channel, `warning` to email.
- `promtool check rules deploy/monitoring/alerts.yml` before each change.

## Alerts

Every alert names its runbook section (`docs/runbooks/application.md`,
`data.md`, `infrastructure.md`) with Meaning, Check, Act and Verify.
`tests/Feature/Platform/AlertRulesTest.php` fails if an alert lacks a
severity, a summary or a runbook section, or uses a `platform_*` metric the
application does not export.
