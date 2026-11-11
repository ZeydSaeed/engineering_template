# Runbook: data (backups and audit trail)

## BackupMissing

- **Meaning:** no successful daily backup for over 26 hours: the recovery point is slipping.
- **Check:** `systemctl list-timers` (the scheduler); `storage/app/private/backups/status.json` (or `BACKUP_DIR`); the scheduler log.
- **Act:** run `php artisan platform:backup manual` now; fix the scheduler.
- **Verify:** `platform_backup_last_success_timestamp_seconds{kind="daily"}` is recent after the next daily run.

## BackupFailed

- **Meaning:** the last daily backup failed; nothing was kept from that run.
- **Check:** `status.json` names the error class; run `php artisan platform:backup manual` to see the full message. Common causes: the backup role's password, disk space, a missing tool (`age`, `pg_dump` of the server's major version).
- **Act:** fix the cause and run a manual backup.
- **Verify:** the manual run reports success and `platform_backup_last_run_ok` returns to 1 at the next daily run.

## AuditChainBroken

- **Meaning:** the audit trail's hash chain does not verify. Records were changed, removed or reordered, which the application cannot do: treat it as a security incident.
- **Check:** `php artisan platform:audit-verify` names the first broken record. Do not change the table.
- **Act:** preserve evidence (a backup of the database as it is now); compare with the last backup whose drill passed; find who had owner or superuser access to the database in the period.
- **Verify:** the investigation is recorded; the chain is anchored again only by a documented, audited procedure (ADR-0013).

## AuditChainNotVerified

- **Meaning:** the daily verification has not run for over 26 hours.
- **Check:** the scheduler; `php artisan platform:audit-verify` by hand.
- **Act:** fix the scheduler.
- **Verify:** `platform_audit_chain_last_verified_timestamp_seconds` is recent.
