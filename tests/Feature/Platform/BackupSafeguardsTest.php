<?php

use App\Platform\Backup\BackupSettings;
use App\Platform\Backup\Pipe;
use App\Platform\Backup\RestoreDrill;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
| Backup and restore safeguards that need no external tool. The full
| backup and restore drill run against PostgreSQL with age and pg_dump;
| their evidence is in docs/evidence/phase-6.
*/

// Shaped like age public keys (age1 + 58 characters); not real keys.
define('KEY_A', 'age1'.str_repeat('q', 58));
define('KEY_B', 'age1'.str_repeat('p', 58));

it('reads recipients given directly or from a file, and refuses anything else', function () {
    $file = storage_path('framework/testing-recipient.txt');
    File::put($file, KEY_B."\n");

    config(['platform.backup.recipients.database' => KEY_A, 'platform.backup.recipients.files' => $file, 'platform.backup.recipients.secrets' => 'not-a-key']);

    expect(BackupSettings::recipient('database'))->toBe(KEY_A)
        ->and(BackupSettings::recipient('files'))->toBe(KEY_B)
        ->and(fn () => BackupSettings::recipient('secrets'))->toThrow(InvalidArgumentException::class, 'No valid age recipient');

    File::delete($file);
});

it('refuses one key for two classes of data', function () {
    config(['platform.backup.recipients' => ['database' => KEY_A, 'files' => KEY_A, 'secrets' => KEY_B]]);

    BackupSettings::assertSeparateKeys();
})->throws(InvalidArgumentException::class, 'its own age key');

it('backs up only secret files inside the application root', function (mixed $entry) {
    config(['platform.backup.secret_files' => ['.env', $entry]]);

    BackupSettings::secretFiles();
})->throws(InvalidArgumentException::class, 'inside the application root')
    ->with(['/etc/shadow', 'C:\\Windows\\win.ini', '../other-app/.env', 'config/../../x', '', 42]);

it('names artifacts by whether the set is encrypted', function () {
    expect(BackupSettings::artifact('database', true))->toBe('database.dump.age')
        ->and(BackupSettings::artifact('files', false))->toBe('files.tar')
        ->and(BackupSettings::encrypted())->toBeTrue();

    config(['platform.backup.encrypt' => false]);
    app()->detectEnvironment(fn () => 'local');

    expect(BackupSettings::encrypted())->toBeFalse();
});

it('refuses unencrypted backups outside the local environment', function (string $environment) {
    config(['platform.backup.encrypt' => false]);
    app()->detectEnvironment(fn () => $environment);

    BackupSettings::encrypted();
})->throws(InvalidArgumentException::class, 'local environment only')->with(['production', 'staging', 'testing']);

it('streams to a file and reads it back unchanged', function () {
    $file = storage_path('framework/testing-pipe-'.bin2hex(random_bytes(4)));
    $payload = str_repeat("line of backup data\n", 20000);
    $source = new Process([PHP_BINARY, '-r', 'echo str_repeat("line of backup data\n", 20000);']);
    $count = new Process([PHP_BINARY, '-r', 'echo strlen(stream_get_contents(STDIN));']);

    try {
        Pipe::run($source, [$count], $file);
        $again = new Process([PHP_BINARY, '-r', 'echo hash("sha256", stream_get_contents(STDIN));']);
        Pipe::fromFile($file, [$again]);

        expect($count->getOutput())->toBe((string) strlen($payload))
            ->and(hash_file('sha256', $file))->toBe(hash('sha256', $payload))
            ->and($again->getOutput())->toBe(hash('sha256', $payload));
    } finally {
        File::delete($file);
    }
});

it('rejects a manifest that lacks an artifact for its encryption mode', function () {
    $set = storage_path('framework/testing-backup-set-3');
    File::ensureDirectoryExists($set);
    File::put("{$set}/manifest.json", (string) json_encode([
        'stamp' => '20261112T000000Z', 'encrypted' => false, 'tables' => [], 'audit_head' => null, 'stored_files' => 0,
        'artifacts' => ['database.dump.age' => ['sha256' => 'x', 'bytes' => 1]],
    ]));

    try {
        app(RestoreDrill::class)->run($set);
    } finally {
        File::deleteDirectory($set);
    }
})->throws(RuntimeException::class, 'lists no database artifact');

it('detects an altered set before decrypting anything', function () {
    $set = storage_path('framework/testing-backup-set');
    File::ensureDirectoryExists($set);
    File::put("{$set}/database.dump.age", 'encrypted bytes');
    File::put("{$set}/manifest.json", (string) json_encode([
        'stamp' => '20261112T000000Z', 'tables' => [], 'audit_head' => null, 'stored_files' => 0,
        'artifacts' => [
            'database.dump.age' => ['sha256' => hash('sha256', 'original bytes'), 'bytes' => 14],
            'files.tar.age' => ['sha256' => 'x', 'bytes' => 1],
            'secrets.tar.age' => ['sha256' => 'x', 'bytes' => 1],
        ],
    ]));

    try {
        app(RestoreDrill::class)->run($set);
    } finally {
        File::deleteDirectory($set);
    }
})->throws(RuntimeException::class, 'Checksum mismatch for database.dump.age');

it('never restores into the application database or a name without "restore"', function (string $target) {
    $set = storage_path('framework/testing-backup-set-2');
    File::ensureDirectoryExists($set);
    $artifacts = ['database.dump' => [], 'files.tar' => [], 'secrets.tar' => []];
    foreach ($artifacts as $name => $value) {
        File::put("{$set}/{$name}", 'plain bytes');
        $artifacts[$name] = ['sha256' => hash('sha256', 'plain bytes'), 'bytes' => 11];
    }
    File::put("{$set}/manifest.json", (string) json_encode(['stamp' => '20261112T000000Z', 'encrypted' => false, 'tables' => [], 'audit_head' => null, 'stored_files' => 0, 'artifacts' => $artifacts]));
    config(['database.connections.'.config('database.default').'.database' => 'engineering_template_restore_live']);
    config(['platform.backup.restore.database' => $target === 'app' ? 'engineering_template_restore_live' : $target]);

    try {
        app(RestoreDrill::class)->run($set);
    } finally {
        File::deleteDirectory($set);
    }
})->throws(RuntimeException::class, 'Refusing to restore into')->with(['app', 'engineering_template', 'production']);
