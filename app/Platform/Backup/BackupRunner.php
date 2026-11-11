<?php

namespace App\Platform\Backup;

use App\Platform\Audit\AuditEvent;
use App\Platform\Files\StoredFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * One encrypted backup set (ADR-0022):
 *
 *   database.dump.age   pg_dump (custom format) as the backup role
 *   files.tar.age       the uploaded files
 *   secrets.tar.age     the configured secret files (.env)
 *   manifest.json       row counts, audit chain head, file totals and the
 *                       SHA-256 of each artifact, readable without a key
 *
 * Each class is encrypted to its own recipient. The dump is also read by
 * `pg_restore --list` on its way to age, so an unreadable dump fails the
 * backup without a plain copy ever touching the disk.
 */
class BackupRunner
{
    /**
     * @return array{kind: string, stamp: string, path: string, seconds: float}
     */
    public function run(string $kind = 'manual'): array
    {
        BackupSettings::assertSeparateKeys();

        $started = microtime(true);
        $stamp = now('UTC')->format('Ymd\THis\Z');
        $path = BackupSettings::directory()."/{$kind}/{$stamp}";
        File::ensureDirectoryExists($path, 0700);

        try {
            $snapshot = $this->database($path);
            $this->tar(Storage::disk((string) config('platform.files.disk'))->path(''), ['.'], 'files', "{$path}/files.tar.age");
            $this->tar(base_path(), BackupSettings::secretFiles(), 'secrets', "{$path}/secrets.tar.age");
            $this->manifest($path, $kind, $stamp, $snapshot);
            $this->prune($kind);
            $this->status($kind, $stamp, true, null);
        } catch (Throwable $exception) {
            File::deleteDirectory($path);
            $this->status($kind, $stamp, false, $exception::class);

            throw $exception;
        }

        return ['kind' => $kind, 'stamp' => $stamp, 'path' => $path, 'seconds' => round(microtime(true) - $started, 1)];
    }

    /**
     * Dumps the database and counts its rows from one snapshot: a
     * REPEATABLE READ transaction exports its snapshot and pg_dump uses it,
     * so the manifest describes exactly the data in the dump, however busy
     * the application is meanwhile.
     *
     * @return array{table_data: int, tables: array<string, int>, audit_head: array{id: int, hash: string}|null, stored_files: int}
     */
    private function database(string $path): array
    {
        $connection = (array) config('database.connections.'.config('database.default'));
        $environment = ['PGPASSWORD' => (string) config('platform.backup.database.password')];

        DB::beginTransaction();

        try {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $snapshotId = (string) DB::scalar('select pg_export_snapshot()');

            $tables = [];

            foreach (DB::select("select tablename from pg_tables where schemaname = 'public' order by tablename") as $row) {
                $tables[$row->tablename] = (int) DB::table($row->tablename)->count();
            }

            $head = AuditEvent::query()->orderByDesc('id')->first(['id', 'hash']);
            $storedFiles = StoredFile::query()->whereIn('status', [StoredFile::PENDING, StoredFile::VERIFIED])->count();

            $dump = new Process([
                BackupSettings::tool('pg_dump'), '--format=custom', '--no-owner', '--no-privileges',
                '--exclude-extension=pg_stat_statements', '--snapshot='.$snapshotId,
                '--host='.$connection['host'], '--port='.$connection['port'],
                '--username='.config('platform.backup.database.username'), '--dbname='.$connection['database'],
            ], null, $environment);

            $encrypt = new Process([BackupSettings::tool('age'), '--encrypt', '-r', BackupSettings::recipient('database'), '-o', "{$path}/database.dump.age"]);
            $list = new Process([BackupSettings::tool('pg_restore'), '--list']);

            Pipe::run($dump, [$encrypt, $list]);
        } finally {
            DB::rollBack();
        }

        return [
            'table_data' => substr_count($list->getOutput(), ' TABLE DATA '),
            'tables' => $tables,
            'audit_head' => $head === null ? null : ['id' => $head->id, 'hash' => $head->hash],
            'stored_files' => $storedFiles,
        ];
    }

    /**
     * @param  list<string>  $entries  relative to $root
     */
    private function tar(string $root, array $entries, string $class, string $target): void
    {
        $archive = new Process([BackupSettings::tool('tar'), '-cf', '-', '-C', $root, ...$entries]);
        $encrypt = new Process([BackupSettings::tool('age'), '--encrypt', '-r', BackupSettings::recipient($class), '-o', $target]);

        Pipe::run($archive, [$encrypt]);
    }

    /**
     * @param  array{table_data: int, tables: array<string, int>, audit_head: array{id: int, hash: string}|null, stored_files: int}  $snapshot
     */
    private function manifest(string $path, string $kind, string $stamp, array $snapshot): void
    {
        $disk = Storage::disk((string) config('platform.files.disk'));
        $files = $disk->allFiles();

        $artifacts = [];

        foreach (['database.dump.age', 'files.tar.age', 'secrets.tar.age'] as $artifact) {
            $artifacts[$artifact] = ['sha256' => (string) hash_file('sha256', "{$path}/{$artifact}"), 'bytes' => (int) filesize("{$path}/{$artifact}")];
        }

        File::put("{$path}/manifest.json", (string) json_encode([
            'kind' => $kind,
            'stamp' => $stamp,
            'application' => config('app.name'),
            'database' => config('database.connections.'.config('database.default').'.database'),
            'postgres' => DB::scalar('show server_version'),
            'tables' => $snapshot['tables'],
            'table_data_entries' => $snapshot['table_data'],
            'audit_head' => $snapshot['audit_head'],
            'stored_files' => $snapshot['stored_files'],
            'files_on_disk' => count($files),
            'recipients' => array_combine(BackupSettings::CLASSES, array_map(BackupSettings::recipient(...), BackupSettings::CLASSES)),
            'artifacts' => $artifacts,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function prune(string $kind): void
    {
        $keep = (int) (((array) config('platform.backup.keep'))[$kind] ?? 7);
        $sets = File::directories(BackupSettings::directory()."/{$kind}");
        sort($sets);

        foreach (array_slice($sets, 0, max(0, count($sets) - $keep)) as $old) {
            File::deleteDirectory($old);
        }
    }

    private function status(string $kind, string $stamp, bool $ok, ?string $error): void
    {
        $file = BackupSettings::directory().'/status.json';
        $status = is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
        $status[$kind] = [
            'stamp' => $stamp,
            'ok' => $ok,
            'error' => $error,
            'finished_at' => now('UTC')->toIso8601String(),
            'last_success_at' => $ok ? now('UTC')->toIso8601String() : ($status[$kind]['last_success_at'] ?? null),
        ];

        File::put($file, (string) json_encode($status, JSON_PRETTY_PRINT));
    }
}
