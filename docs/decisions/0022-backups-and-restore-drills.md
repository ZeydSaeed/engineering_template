# ADR-0022: Encrypted backups with a key per class, proven by restore drills

- Status: Accepted
- Date: 2026-11-12

## Context

A backup nobody has restored is a hope, not a backup. A backup readable on
the server it protects is a second copy of everything for whoever breaks
in. One key for everything makes one leaked key a total loss.

## Problem

Back up the database, uploaded files and secrets so that they cannot be read
or altered unnoticed, and prove regularly that they restore.

## Options

1. `pg_dump` files on the server, plain.
2. One encrypted archive with one key.
3. Separate encrypted artifacts per class (database, files, secrets), each
   to its own age recipient, with a manifest and a full restore drill.

## Decision

Option 3 (`App\Platform\Backup`).

- `platform:backup daily|manual` (scheduled 02:00): the database is dumped
  as the read-only backup role from a REPEATABLE READ snapshot that also
  produces the manifest's row counts and audit chain head, so manifest and
  dump describe the same instant. The dump streams into age and into
  `pg_restore --list` at once: no plain copy on disk, and an unreadable dump
  fails the backup. Files and secrets are tar streams into age.
- Three age recipients (database, files, secrets); the backup refuses to run
  if two are equal. Only public keys are on the server; private identities
  live offline and on the machine that runs restores.
- `manifest.json` (readable without a key): row counts per table, audit head,
  stored-file count, recipients, SHA-256 and size of each artifact.
- Retention per kind (daily 7, manual 10); `status.json` feeds the metrics.
- `platform:restore-drill [set]`: checksums before decrypting; restore into a
  dedicated database whose name contains "restore" and is neither the
  application's nor the test database; single transaction, exit on error;
  row counts, audit chain and head, every stored file's SHA-256, and the
  secrets archive. Prints restore time (RTO measured) and backup age.
- `pg_stat_statements` is excluded from dumps (restoring it needs a
  superuser).

## Consequences

Measured locally (docs/evidence/phase-6): 1,000 users, 53,579 audit
records, 5 files: backup 2.8 s, restore 2.6 s, full drill 10.5 s. A changed
byte fails the drill at the checksum; a changed byte with a matching
checksum fails at age's authentication.

## Security implications

A server compromise exposes only public keys and ciphertext. A leaked key
exposes one class. Tampering is detected.

## Performance implications

Streaming keeps memory flat; a dump holds one REPEATABLE READ transaction
open for its duration (watch for very long dumps blocking vacuum).

## Amendment 2026-11-12: unencrypted backups (owner's decision)

The owner chose to be able to back up without encryption, so that losing
the private keys can never make the backups unreadable.
`BACKUP_ENCRYPT=false` (any environment) writes `database.dump`,
`files.tar` and `secrets.tar` in plain form; the manifest records
`"encrypted": false`, the command prints a warning on every run, and the
restore drill reads both kinds. The template default stays encrypted.

Accepted risk, signed off by the owner: with plain sets, anyone who can
read the backup directory or any copy of it (the server, object storage, a
USB drive) reads the whole database, every uploaded file and the `.env`
secrets (database passwords, APP_KEY, AUDIT_HMAC_KEY). The manifest
checksums then show accidental damage only, not tampering. Off-host copies
of plain sets must sit in storage with its own access control and
encryption at rest.

The alternative offered and not chosen: keep encryption and protect the key
files with a passphrase (`age -p`), so a lost key file is recoverable from
the passphrase.

### Narrowed 2026-11-12: local machine only (owner's decision)

The owner then limited unencrypted backups to the local development
machine. `BACKUP_ENCRYPT=false` is now honoured only when `APP_ENV=local`:

- `BackupSettings::encrypted()` refuses it in any other environment, so no
  plain set can be written there;
- `EnvironmentValidator` reports `BACKUP_ENCRYPT must be true.` in
  production and staging, so such a server refuses to start;
- both are covered by tests (BackupSafeguardsTest, EnvironmentValidatorTest).

The local `.env` of the owner's development machine is the only place
with `BACKUP_ENCRYPT=false`. The risks above still apply to the sets it
writes (they hold the local `.env` and development data); keep them off
shared or synced folders.

## Reversal or migration strategy

Point-in-time recovery (WAL archiving with pgBackRest) is added on the
target host (docs/operations/recovery.md); logical backups stay as the
portable, verifiable second line.
