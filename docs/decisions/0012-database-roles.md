# ADR-0012: Separate database roles and conventions

- Status: Accepted
- Date: 2026-11-11

## Context

A web process that can alter the schema or rewrite history turns any SQL
injection or code bug into a structural one. Timestamps without a time zone
and plain `json` cause silent errors across time zones and block indexing.

## Problem

Limit what the running application can do in PostgreSQL, and fix the column
conventions before any module adds tables.

## Options

1. One role for everything (the framework default).
2. Three roles: owner for migrations, runtime for data, read-only for backups.

## Decision

Option 2.

| Role           | Rights                                                                                                                                                             | Used by                                                 |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------- |
| `<app>_owner`  | owns the database and schema; DDL                                                                                                                                  | migrations only (`scripts/db/migrate.ps1`, deployments) |
| `<app>_app`    | SELECT/INSERT/UPDATE/DELETE through default privileges; `lock_timeout 5s`, `statement_timeout 30s`, `idle_in_transaction_session_timeout 60s`; connection limit 40 | the application                                         |
| `<app>_backup` | `pg_read_all_data`; connection limit 2                                                                                                                             | backups                                                 |

Migrations revoke rights the runtime role must not have (append-only tables,
ADR-0013), using `platform.database.app_role`.

Conventions, checked by `tests/Feature/Database/PostgresConventionsTest.php`:
`timestamptz` for every timestamp, `jsonb` for every JSON column, an index for
every foreign key, `RESTRICT` on delete unless the ADR of the table says
otherwise. Tests may connect only to the dedicated test database
(`tests/TestCase.php`).

## Consequences

- The runtime role cannot drop tables or disable triggers.
- Migrations need the owner's credentials, kept apart from the application's.

## Security implications

Least privilege inside the database; an injection in the application cannot
run DDL or rewrite the audit trail.

## Performance implications

Timeouts stop runaway statements and abandoned transactions from holding
locks. Connection limits keep one role from exhausting the server.

## Reversal or migration strategy

Grants are plain SQL in the setup script and migrations; widening them is a
reviewed change.
