<?php

use App\Platform\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
| Rules only PostgreSQL can show (docs/standards/database.md). Skipped on
| SQLite; run them with `composer test:pgsql`.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL only.');
    }
});

it('reads times in the application time zone whatever the server is set to', function () {
    // A naive time (Laravel writes some, such as reset-token times) is read
    // in the session zone; a mismatch shifted them by hours before the fix.
    expect(DB::scalar('show timezone'))->toBe(config('app.timezone'));
});

it('stores every timestamp with its time zone', function () {
    $columns = DB::select(<<<'SQL'
        select table_name || '.' || column_name as col from information_schema.columns
        where table_schema = 'public' and data_type = 'timestamp without time zone' order by 1
        SQL);

    expect(array_column($columns, 'col'))->toBe([]);
});

it('stores JSON as jsonb', function () {
    $columns = DB::select(<<<'SQL'
        select table_name || '.' || column_name as col from information_schema.columns
        where table_schema = 'public' and data_type = 'json' order by 1
        SQL);

    expect(array_column($columns, 'col'))->toBe([]);
});

it('indexes every foreign key', function () {
    $unindexed = DB::select(<<<'SQL'
        select c.conrelid::regclass::text || ' ' || c.conname as fk
        from pg_constraint c
        where c.contype = 'f'
          and not exists (
              select 1 from pg_index i
              where i.indrelid = c.conrelid
                and (i.indkey::int2[])[0:cardinality(c.conkey) - 1] = c.conkey
          )
        order by 1
        SQL);

    expect(array_column($unindexed, 'fk'))->toBe([]);
});

it('rejects updating, deleting or truncating audit records', function (string $statement) {
    app(AuditLogger::class)->record('test.kept');

    expect(fn () => DB::statement($statement))->toThrow(QueryException::class, 'audit_events is append-only');
})->with([
    'update' => "update audit_events set action = 'changed'",
    'delete' => 'delete from audit_events',
    'truncate' => 'truncate audit_events',
]);

it('denies the runtime role any change to audit records', function () {
    $role = (string) config('platform.database.app_role');

    if ($role === '' || DB::scalar('select count(*) from pg_roles where rolname = ?', [$role]) === 0) {
        $this->markTestSkipped('DB_APP_ROLE is not set up (scripts/db/create-local-databases.ps1).');
    }

    expect(DB::scalar("select has_table_privilege(?, 'audit_events', 'INSERT')", [$role]))->toBeTrue()
        ->and(DB::scalar("select has_table_privilege(?, 'audit_events', 'UPDATE')", [$role]))->toBeFalse()
        ->and(DB::scalar("select has_table_privilege(?, 'audit_events', 'DELETE')", [$role]))->toBeFalse()
        ->and(DB::scalar("select has_table_privilege(?, 'audit_events', 'TRUNCATE')", [$role]))->toBeFalse()
        ->and(DB::scalar("select has_table_privilege(?, 'users', 'UPDATE')", [$role]))->toBeTrue();
});
