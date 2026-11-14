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
 * Unencrypted sets (manifest "encrypted": false) are read directly; for
 * them the checksums show damage, not tampering, since whoever can change
 * an artifact can change the manifest too.
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
        $encrypted = $manifest['encrypted'];
        $checks = [];

        foreach ($manifest['artifacts'] as $name => $artifact) {
            if (! hash_equals($artifact['sha256'], (string) hash_file('sha256', "{$set}/{$name}"))) {
                throw new RuntimeException("Checksum mismatch for {$name}: the set was altered or damaged.");
            }
        }
        $checks[] = 'artifact checksums match the manifest';

        $this->connect();
        $restoreStarted = microtime(true);
        $this->restoreDatabase($set, $encrypted);
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

        $checks[] = $this->verifyFiles($set, $manifest, $encrypted);

        $this->verifySecrets($set, $encrypted);
        $checks[] = $encrypted ? 'secrets decrypt with their own key' : 'secrets archive readable (UNENCRYPTED set)';

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
     * @return array{stamp: string, encrypted: bool, tables: array<string, int>, audit_head: array{id: int, hash: string}|null, stored_files: int, artifacts: array<string, array{sha256: string, bytes: int}>}
     */
    private function manifest(string $set): array
    {
        $manifest = json_decode((string) @file_get_contents("{$set}/manifest.json"), true);

        if (! is_array($manifest) || ! isset($manifest['stamp'], $manifest['tables'], $manifest['artifacts'])) {
            throw new RuntimeException("No readable manifest in {$set}.");
        }

        // Sets written before the setting existed are encrypted.
        $manifest['encrypted'] = ($manifest['encrypted'] ?? true) !== false;

        foreach (BackupSettings::CLASSES as $class) {
            if (! isset($manifest['artifacts'][BackupSettings::artifact($class, $manifest['encrypted'])])) {
                throw new RuntimeException("The manifest lists no {$class} artifact.");
            }
        }

        /** @var array{stamp: string, encrypted: bool, tables: array<string, int>, audit_head: array{id: int, hash: string}|null, stored_files: int, artifacts: array<string, array{sha256: string, bytes: int}>} $manifest */
        return $manifest;
    }

    /**
     * Feeds one class's artifact, decrypted when the set is encrypted, to
     * the consumers.
     *
     * @param  list<Process>  $consumers
     */
    private function read(string $set, string $class, bool $encrypted, array $consumers): void
    {
        $file = $set.'/'.BackupSettings::artifact($class, $encrypted);

        if (! $encrypted) {
            Pipe::fromFile($file, $consumers);

            return;
        }

        Pipe::run(new Process([BackupSettings::tool('age'), '--decrypt', '-i', BackupSettings::identity($class), $file]), $consumers);
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
            'host' => (string) (config('platform.backup.restore.host') ?: $base['host']),
            'port' => (string) (config('platform.backup.restore.port') ?: $base['port']),
            'username' => (string) config('platform.backup.restore.username'),
            'password' => (string) config('platform.backup.restore.password'),
        ]]);

        DB::purge(self::CONNECTION);
    }

    private function restoreDatabase(string $set, bool $encrypted): void
    {
        $connection = (array) config('database.connections.'.self::CONNECTION);

        // A clean target each time.
        DB::connection(self::CONNECTION)->unprepared('DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;');

        $restore = new Process([
            BackupSettings::tool('pg_restore'), '--no-owner', '--no-privileges', '--exit-on-error', '--single-transaction',
            '--host='.$connection['host'], '--port='.$connection['port'],
            '--username='.$connection['username'], '--dbname='.$connection['database'],
        ], null, ['PGPASSWORD' => (string) $connection['password']]);

        $this->read($set, 'database', $encrypted, [$restore]);
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
    private function verifyFiles(string $set, array $manifest, bool $encrypted): string
    {
        $target = storage_path('framework/restore-drill-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($target, 0700);

        try {
            $this->read($set, 'files', $encrypted, [new Process([BackupSettings::tool('tar'), '-xf', '-', '-C', $target])]);

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

    private function verifySecrets(string $set, bool $encrypted): void
    {
        $list = new Process([BackupSettings::tool('tar'), '-tf', '-']);

        try {
            $this->read($set, 'secrets', $encrypted, [$list]);
        } catch (Throwable $exception) {
            throw new RuntimeException('Secrets could not be read: '.$exception->getMessage(), previous: $exception);
        }

        foreach ((array) config('platform.backup.secret_files') as $file) {
            if (! str_contains($list->getOutput(), (string) $file)) {
                throw new RuntimeException("Secrets archive lacks {$file}.");
            }
        }
    }
}
