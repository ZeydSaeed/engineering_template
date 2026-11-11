<?php

namespace App\Platform\Backup;

use App\Platform\Audit\VerifyAuditChain;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Restores one backup set into the disposable restore database and proves
 * it is complete (ADR-0022). A backup counts as good only after this passes.
 *
 *  1. artifact checksums match the manifest (before decrypting anything)
 *  2. the database decrypts and restores in one transaction
 *  3. every table has the manifest's row count
 *  4. the audit chain verifies and ends at the manifest's head
 *  5. every stored file restored from the archive has its recorded SHA-256
 *  6. the secrets decrypt with their own key
 *
 * Returns the timings: restore time is the recovery time (RTO) measured.
 */
class RestoreDrill
{
    public const CONNECTION = 'restore_drill';

    /**
     * @return array{set: string, checks: list<string>, restore_seconds: float, total_seconds: float, backup_age_hours: float}
     */
    public function run(string $set): array
    {
        $started = microtime(true);
        $manifest = $this->manifest($set);
        $checks = [];

        foreach ($manifest['artifacts'] as $name => $artifact) {
            if (! hash_equals($artifact['sha256'], (string) hash_file('sha256', "{$set}/{$name}"))) {
                throw new RuntimeException("Checksum mismatch for {$name}: the set was altered or damaged.");
            }
        }
        $checks[] = 'artifact checksums match the manifest';

        $this->connect();
        $restoreStarted = microtime(true);
        $this->restoreDatabase($set);
        $restoreSeconds = round(microtime(true) - $restoreStarted, 1);
        $checks[] = "database restored ({$restoreSeconds} s)";

        $restored = DB::connection(self::CONNECTION);

        foreach ($manifest['tables'] as $table => $expected) {
            $actual = (int) $restored->table($table)->count();

            if ($actual !== $expected) {
                throw new RuntimeException("Row count mismatch in {$table}: backup {$expected}, restored {$actual}.");
            }
        }
        $checks[] = 'row counts match in '.count($manifest['tables']).' tables';

        $this->verifyAuditChain($manifest);
        $checks[] = 'audit chain intact up to the manifest head';

        $checks[] = $this->verifyFiles($set, $manifest);

        $this->verifySecrets($set);
        $checks[] = 'secrets decrypt with their own key';

        return [
            'set' => $set,
            'checks' => $checks,
            'restore_seconds' => $restoreSeconds,
            'total_seconds' => round(microtime(true) - $started, 1),
            // From the backup to now: positive (Carbon 3 differences are signed).
            'backup_age_hours' => round(CarbonImmutable::createFromFormat('Ymd\THis\Z', $manifest['stamp'], 'UTC')?->diffInMinutes(now('UTC')) / 60, 2),
        ];
    }

    /**
     * @return array{stamp: string, tables: array<string, int>, audit_head: array{id: int, hash: string}|null, stored_files: int, artifacts: array<string, array{sha256: string, bytes: int}>}
     */
    private function manifest(string $set): array
    {
        $manifest = json_decode((string) @file_get_contents("{$set}/manifest.json"), true);

        if (! is_array($manifest) || ! isset($manifest['stamp'], $manifest['tables'], $manifest['artifacts'])) {
            throw new RuntimeException("No readable manifest in {$set}.");
        }

        /** @var array{stamp: string, tables: array<string, int>, audit_head: array{id: int, hash: string}|null, stored_files: int, artifacts: array<string, array{sha256: string, bytes: int}>} $manifest */
        return $manifest;
    }

    /**
     * The restore target: the configured disposable database only, never
     * the application's or the test database.
     */
    private function connect(): void
    {
        $database = (string) config('platform.backup.restore.database');
        $base = (array) config('database.connections.pgsql');
        $forbidden = [
            (string) config('database.connections.'.config('database.default').'.database'),
            (string) config('platform.database.testing_name'),
        ];

        if ($database === '' || ! str_contains($database, 'restore') || in_array($database, $forbidden, true)) {
            throw new RuntimeException("Refusing to restore into [{$database}]: use a dedicated database whose name contains 'restore'.");
        }

        config(['database.connections.'.self::CONNECTION => [
            ...$base,
            'database' => $database,
            'username' => (string) config('platform.backup.restore.username'),
            'password' => (string) config('platform.backup.restore.password'),
        ]]);

        DB::purge(self::CONNECTION);
    }

    private function restoreDatabase(string $set): void
    {
        $connection = (array) config('database.connections.'.self::CONNECTION);

        // A clean target each time.
        DB::connection(self::CONNECTION)->unprepared('DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;');

        $decrypt = new Process([BackupSettings::tool('age'), '--decrypt', '-i', BackupSettings::identity('database'), "{$set}/database.dump.age"]);
        $restore = new Process([
            BackupSettings::tool('pg_restore'), '--no-owner', '--no-privileges', '--exit-on-error', '--single-transaction',
            '--host='.$connection['host'], '--port='.$connection['port'],
            '--username='.$connection['username'], '--dbname='.$connection['database'],
        ], null, ['PGPASSWORD' => (string) $connection['password']]);

        Pipe::run($decrypt, [$restore]);
        DB::purge(self::CONNECTION);
    }

    /**
     * @param  array{audit_head: array{id: int, hash: string}|null}  $manifest
     */
    private function verifyAuditChain(array $manifest): void
    {
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection(self::CONNECTION);

        try {
            $result = app(VerifyAuditChain::class)->run();
            $head = DB::table('audit_events')->orderByDesc('id')->first(['id', 'hash']);
        } finally {
            DB::setDefaultConnection($default);
        }

        if ($result['broken_at'] !== null) {
            throw new RuntimeException("Restored audit chain broken at record {$result['broken_at']}.");
        }

        $expected = $manifest['audit_head'];

        if (($expected === null) !== ($head === null) || ($head !== null && $expected !== null && ((int) $head->id !== $expected['id'] || $head->hash !== $expected['hash']))) {
            throw new RuntimeException('Restored audit chain does not end at the manifest head.');
        }
    }

    /**
     * @param  array{stored_files: int}  $manifest
     */
    private function verifyFiles(string $set, array $manifest): string
    {
        $target = storage_path('framework/restore-drill-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($target, 0700);

        try {
            $decrypt = new Process([BackupSettings::tool('age'), '--decrypt', '-i', BackupSettings::identity('files'), "{$set}/files.tar.age"]);
            $extract = new Process([BackupSettings::tool('tar'), '-xf', '-', '-C', $target]);
            Pipe::run($decrypt, [$extract]);

            $rows = DB::connection(self::CONNECTION)->table('stored_files')
                ->whereIn('status', ['pending', 'verified'])
                ->get(['path', 'sha256']);

            if ($rows->count() !== $manifest['stored_files']) {
                throw new RuntimeException("Stored file records: backup {$manifest['stored_files']}, restored {$rows->count()}.");
            }

            foreach ($rows as $row) {
                $file = "{$target}/{$row->path}";

                if (! is_file($file) || ! hash_equals((string) $row->sha256, (string) hash_file('sha256', $file))) {
                    throw new RuntimeException("Restored file {$row->path} is missing or differs from its recorded SHA-256.");
                }
            }

            return "{$rows->count()} stored files restored with matching SHA-256";
        } finally {
            File::deleteDirectory($target);
        }
    }

    private function verifySecrets(string $set): void
    {
        $decrypt = new Process([BackupSettings::tool('age'), '--decrypt', '-i', BackupSettings::identity('secrets'), "{$set}/secrets.tar.age"]);
        $list = new Process([BackupSettings::tool('tar'), '-tf', '-']);

        try {
            Pipe::run($decrypt, [$list]);
        } catch (Throwable $exception) {
            throw new RuntimeException('Secrets could not be decrypted: '.$exception->getMessage(), previous: $exception);
        }

        foreach ((array) config('platform.backup.secret_files') as $file) {
            if (! str_contains($list->getOutput(), (string) $file)) {
                throw new RuntimeException("Secrets archive lacks {$file}.");
            }
        }
    }
}
