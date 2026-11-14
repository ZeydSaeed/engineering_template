# Database standards (PostgreSQL)

Status: implemented and tested; the conventions are checked on every table,
including the Requests module's (PostgresConventionsTest; reviewed in Phase 10).

## Roles (ADR-0012)

Three roles per application: `<app>_owner` (migrations), `<app>_app`
(runtime, DML only, with `lock_timeout`, `statement_timeout` and
`idle_in_transaction_session_timeout`), `<app>_backup` (read only). Local
setup: `scripts/db/create-local-databases.ps1`; migrations:
`scripts/db/migrate.ps1`.

## Columns

| Use                          | Type                                                |
| ---------------------------- | --------------------------------------------------- |
| Internal key                 | `bigint` identity (`$table->id()`)                  |
| Key in URLs                  | `uuid` (v7 from `Str::uuid7()`), unique             |
| Time                         | `timestampTz` / `timestampsTz()`; never `timestamp` |
| JSON                         | `jsonb`; never `json`                               |
| Exact numbers (marks, money) | `decimal(p, s)`; never float                        |
| Fixed choices                | string with a CHECK constraint or an enum cast      |

The connection sets the session time zone to UTC (`config/database.php`),
matching `config('app.timezone')`. Laravel writes some times without an
offset; with a server set to another zone (the local server is
`Asia/Baghdad`), those were shifted by the server's offset and password
reset tokens expired at once. Checked by `PostgresConventionsTest`.

## Constraints

- Foreign keys `RESTRICT` on delete unless the table's ADR says otherwise.
- Every foreign key has an index (a unique or composite index whose leading
  columns match counts).
- State rules the database can express go into CHECK constraints, added in
  PostgreSQL-only blocks of the migration.
- Every index has a comment in its migration saying which query it serves.

## Migrations

- Reversible (`down()` works); CI runs migrate, rollback, migrate.
- Changes to live tables use expand/contract: add, backfill, switch, remove
  in a later release.
- Large indexes on live tables: `CREATE INDEX CONCURRENTLY` in a migration
  without a transaction.

## Writes

Actions own transactions (`DB::transaction(fn, 3)`), lock the aggregate root
with `lockForUpdate()`, re-check state under the lock, and keep a fixed lock
order. User edits carry a `lock_version`, checked on save (the Requests
module, ADR-0033).

## Tests

`tests/TestCase.php` refuses any database except in-memory SQLite and the
dedicated test database. `composer test:pgsql` runs the suite on PostgreSQL;
`tests/Feature/Database/PostgresConventionsTest.php` enforces the column and
index rules above.
