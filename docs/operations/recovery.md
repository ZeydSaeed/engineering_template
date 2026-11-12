# Backup and recovery

Status: logical backups and restore drills implemented and verified locally
(docs/evidence/phase-6). Point-in-time recovery: Planned, NOT TESTED.

## Objectives (initial; each application sets its own)

|                             | Target                                                     | Basis                                                                                                                                       |
| --------------------------- | ---------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| RPO (data that may be lost) | 24 h with nightly backups; minutes once WAL archiving runs | backup schedule; `BackupMissing` alerts after 26 h                                                                                          |
| RTO (time to serve again)   | under 1 h for the template's data size                     | measured restore 2.6 s, full drill 10.5 s for 1,000 users and 53,579 audit records; most of an RTO is provisioning and DNS, not the restore |

## Keys

Three age key pairs: database, files, secrets. On the server: the three
**public** recipients only (`BACKUP_RECIPIENT_*`). The private identities
(`*.agekey`) are kept offline (two copies, e.g. two USB drives in separate
places) and on the machine that runs restores. Without them no backup can
be read. Losing all copies of a key loses every backup of that class.

Create them once per application:

```
age-keygen -o database.agekey && age-keygen -y database.agekey > database.recipient
(the same for files and secrets)
```

### Unencrypted sets

Local development machine only: any other environment refuses
`BACKUP_ENCRYPT=false`, and production and staging refuse to start with it.
With `BACKUP_ENCRYPT=false` (ADR-0022, amendment) no keys are needed: sets
hold `database.dump`, `files.tar` and `secrets.tar`, readable by anyone who
can read them. Restore them as below without the `age -d` step
(`pg_restore ... database.dump`, `tar -xf files.tar`, `tar -xf secrets.tar`).
Keep every copy in access-controlled storage with encryption at rest.

## Backups

`php artisan platform:backup daily` runs nightly from the scheduler;
`platform:backup manual` before risky changes. Sets go to `BACKUP_DIR`
(`daily/<stamp>`, `manual/<stamp>`), seven daily and ten manual kept. Copy
sets off the host (object storage with object lock, or a pull from another
machine); the server's own copy does not survive the loss of the server.

## Restore drill (monthly, and after every change to backups)

On a machine with the identities and a disposable PostgreSQL database whose
name contains `restore`, owned by the restoring role:

```
php artisan platform:restore-drill            # newest set
php artisan platform:restore-drill <set path>
```

It checks the artifacts against the manifest, restores in one transaction,
compares every table's row count, verifies the audit chain to the manifest's
head, checks every stored file's SHA-256, and decrypts the secrets. Record the
output in the operations log. A backup is good only once a drill passed.

## Real recovery

1. Provision the host from the templates (deploy/).
2. Create the database and roles (owner, runtime, backup).
3. Restore the database: `age -d -i database.agekey database.dump.age | pg_restore --no-owner --no-privileges --exit-on-error --single-transaction -d <db>` as the owner.
4. Run `php artisan migrate --force` as the owner: it re-applies grants and
   revokes (the dump has no privileges).
5. Restore files: `age -d -i files.agekey files.tar.age | tar -xf - -C <files disk root>`.
6. Restore secrets: `age -d -i secrets.agekey secrets.tar.age | tar -xf - -C <app root>`; rotate any secret that may have leaked with the old host.
7. `php artisan platform:health`, `platform:audit-verify`, then open traffic.

## Point-in-time recovery (Planned, NOT TESTED)

On the target Linux host: pgBackRest with WAL archiving to object storage,
a full backup weekly, differentials daily, encrypted repository. Not enabled
on the local development server, where `archive_mode` would apply to every
database on it.
