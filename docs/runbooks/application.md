# Runbook: application

Each section: what the alert means, what to check, what to do, and how to
know it is fixed. Commands run on the application host as the application
user, from the current release directory.

## ApplicationDown

- **Meaning:** Prometheus cannot read `/metrics`.
- **Check:** `systemctl status php8.5-fpm nginx`; `curl -sk https://127.0.0.1/health/ready -H "Host: HOST"`; `php artisan platform:health`; the last lines of `storage/logs`.
- **Act:** restart the failing service; if a release just went out, roll back: `sudo SMOKE_HOST=HOST scripts/deploy/rollback.sh` (the previous live release, from `/srv/APP/deploys.log`; it reloads PHP-FPM and smoke-tests; ADR-0032).
- **Verify:** `/health/ready` returns `{"status":"ok","release":"<the expected commit>"}`; the alert resolves within two scrapes.

## QueueBacklog

- **Meaning:** more than 100 jobs on one queue for 10 minutes.
- **Check:** `systemctl status APP-queue@<queue>`; worker logs; `php artisan queue:monitor`.
- **Act:** start the worker if stopped; if it runs but cannot keep up, add an instance (`APP-queue@<queue>-2`) within the memory budget (docs/operations/capacity.md).
- **Verify:** `platform_queue_jobs` for the queue falls below 100.

## QueueStalled

- **Meaning:** the oldest waiting job is over 10 minutes old: no worker is taking jobs from this queue.
- **Check:** is a worker for the queue running? Is it stuck on one job (`ps`, the worker log)? Is the database or Redis reachable?
- **Act:** restart the worker (`systemctl restart APP-queue@<queue>`); a stuck job is released after `retry_after`.
- **Verify:** `platform_queue_oldest_job_age_seconds` returns to near zero.

## JobsFailing

- **Meaning:** jobs used all their attempts in the last hour.
- **Check:** `php artisan queue:failed`; the log line `Job failed after its last attempt.` gives the job class and uuid (no payload).
- **Act:** fix the cause, then `php artisan queue:retry <uuid>` (jobs are idempotent).
- **Verify:** the retried jobs leave `failed_jobs`; no new failures.

## OutboxMessagesDead

- **Meaning:** outbox messages could not be queued after every attempt; the effect they announce has not happened.
- **Check:** `select uuid, topic, attempts, last_error from outbox_messages where status = 'dead'`.
- **Act:** fix the cause (often the queue connection); then `update outbox_messages set status = 'pending', attempts = 0, available_at = now() where status = 'dead'` (consumers apply each message once).
- **Verify:** the rows become `dispatched`; `platform_outbox_messages{status="dead"}` returns to 0.

## OutboxStalled

- **Meaning:** a due message has waited over 5 minutes: the relay is not running.
- **Check:** `systemctl status APP-outbox-relay`; the scheduler (`systemctl list-timers`).
- **Act:** start the relay; `php artisan platform:outbox-relay` relays once by hand.
- **Verify:** `platform_outbox_oldest_pending_age_seconds` returns to near zero.

## FileScansStalled

- **Meaning:** an upload has waited over 30 minutes for its scan; it cannot be downloaded meanwhile.
- **Check:** the `files` queue worker; ClamAV (`systemctl status clamav-daemon`, `clamdscan --version`); the log for `ScannerUnavailable`.
- **Act:** restart the scanner or the worker; then `php artisan platform:files-sweep` queues stale scans again.
- **Verify:** `platform_files{status="pending"}` falls; the oldest pending age returns to minutes.

## Deploying a release (no alert; the procedure)

- **Artifact:** `engineering-template-<sha>.tar.gz` and its `.sha256` from the
  commit's `release` workflow run (ADR-0031), never a checkout.
- **Staging:** the `deploy-staging` workflow, started by hand with the commit
  SHA (an approver of the `staging` environment confirms).
- **By hand on a server:** copy the artifact, its checksum, `scripts/deploy/`
  and `scripts/release/verify.sh`; run
  `sudo SMOKE_HOST=HOST scripts/deploy/deploy.sh engineering-template-<sha>.tar.gz`.
  It verifies, unpacks to `/srv/APP/releases/<sha>`, migrates, caches,
  switches `current`, reloads PHP-FPM, smoke-tests, and returns to the
  previous release by itself if the smoke test fails (exit 1).
- **Migrations:** forward only, and the previous release must keep working
  with the new schema (add first, remove in a later release). A rollback
  switches code, never the database; restoring data is
  `docs/runbooks/data.md`.
- **Verify:** `scripts/deploy/smoke.sh <sha>` passes; `deploys.log` ends
  with `<sha> live`.
