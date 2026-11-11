<?php

use App\Models\User;
use App\Platform\Audit\AuditEvent;
use App\Platform\Audit\AuditLogger;
use App\Platform\Exceptions\DomainRuleViolation;
use App\Platform\Files\Contracts\FileScanner;
use App\Platform\Files\FileStore;
use App\Platform\Files\ScannerUnavailable;
use App\Platform\Files\ScanResult;
use App\Platform\Files\ScanStoredFile;
use App\Platform\Files\StoredFile;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\Files;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('files');
});

it('stores a file under a random name, scans it and marks it verified', function () {
    $user = User::factory()->create();

    $file = app(FileStore::class)->store(Files::upload('../../Report "final".pdf', Files::pdf()), actor: $user)->refresh();

    expect($file->status)->toBe(StoredFile::VERIFIED)
        ->and($file->mime_type)->toBe('application/pdf')
        ->and($file->original_name)->toBe('Report final.pdf')
        ->and($file->path)->toMatch('#^\d{4}/\d{2}/[A-Za-z0-9]{40}$#')
        ->and($file->sha256)->toBe(hash('sha256', Files::pdf()))
        ->and($file->scanned_at)->not->toBeNull()
        ->and($file->uploaded_by)->toBe($user->id)
        ->and(AuditEvent::query()->where('action', 'file.uploaded')->count())->toBe(1);

    Storage::disk('files')->assertExists($file->path);
});

it('refuses a file whose bytes do not match its extension', function () {
    app(FileStore::class)->store(Files::upload('invoice.pdf', "MZ\x90\x00 this is a program"));
})->throws(DomainRuleViolation::class, 'This type of file is not accepted.');

it('refuses an extension outside the allow-list, whatever the bytes', function () {
    app(FileStore::class)->store(Files::upload('page.html', '<html><script>alert(1)</script></html>'));
})->throws(DomainRuleViolation::class, 'This type of file is not accepted.');

it('refuses a file over the size limit', function () {
    config(['platform.files.max_kb' => 1]);

    app(FileStore::class)->store(Files::upload('big.csv', str_repeat("a,b\n", 600)));
})->throws(DomainRuleViolation::class, 'larger than 1 KB');

it('rejects a file the scanner refuses, deletes its bytes and audits it', function () {
    $file = app(FileStore::class)->store(Files::upload('form.pdf', Files::pdf('<< /S /JavaScript >>')))->refresh();

    expect($file->status)->toBe(StoredFile::REJECTED)
        ->and($file->rejection_reason)->toContain('/JavaScript')
        ->and(AuditEvent::query()->where('action', 'file.rejected')->count())->toBe(1);

    Storage::disk('files')->assertMissing($file->path);
});

it('keeps a file pending when the scanner cannot answer', function () {
    app()->bind(FileScanner::class, fn () => new class implements FileScanner
    {
        public function scan($stream, string $mimeType): ScanResult
        {
            throw new ScannerUnavailable('daemon down');
        }
    });

    try {
        app(FileStore::class)->store(Files::upload('scan.png', Files::png()));
    } catch (ScannerUnavailable) {
        // The sync queue surfaces the job's failure; a real worker retries it.
    }

    $file = StoredFile::query()->sole();

    expect($file->status)->toBe(StoredFile::PENDING)
        ->and(fn () => app(FileStore::class)->download($file))->toThrow(NotFoundHttpException::class);

    Storage::disk('files')->assertExists($file->path);
});

it('keeps the record and its bytes when the queue is down after the commit', function () {
    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));
    app()->instance(Dispatcher::class, $bus);

    expect(fn () => app(FileStore::class)->store(Files::upload('scan.png', Files::png())))
        ->toThrow(RuntimeException::class, 'queue down');

    $file = StoredFile::query()->sole();

    expect($file->status)->toBe(StoredFile::PENDING);
    Storage::disk('files')->assertExists($file->path);
});

it('serves only verified files, with safe headers', function () {
    $file = app(FileStore::class)->store(Files::upload('scan.png', Files::png()))->refresh();
    Route::middleware('web')->get('/_test/files/{file}', fn (StoredFile $file) => app(FileStore::class)->download($file));

    $response = $this->get("/_test/files/{$file->uuid}")->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('scan.png')
        ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox")
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    $file->forceFill(['status' => StoredFile::REJECTED])->save();
    $this->get("/_test/files/{$file->uuid}")->assertNotFound();
});

it('sweeps: rescans stale pending files and deletes old bytes without a record', function () {
    $file = app(FileStore::class)->store(Files::upload('a.png', Files::png()))->refresh();
    $file->forceFill(['status' => StoredFile::PENDING, 'scanned_at' => null])->save();
    StoredFile::query()->whereKey($file->id)->update(['created_at' => now()->subHour()]);

    Storage::disk('files')->put('2020/01/orphan', 'left behind');
    touch(Storage::disk('files')->path('2020/01/orphan'), now()->subDays(2)->getTimestamp());

    $this->artisan('platform:files-sweep --dry-run')->assertSuccessful();
    Storage::disk('files')->assertExists('2020/01/orphan');

    $this->artisan('platform:files-sweep')->expectsOutputToContain('Rescanned 1')->assertSuccessful();

    Storage::disk('files')->assertMissing('2020/01/orphan');
    expect($file->refresh()->status)->toBe(StoredFile::VERIFIED);
});

it('no longer exposes the local disk over HTTP', function () {
    expect(Route::has('storage.local'))->toBeFalse()
        ->and(Route::has('storage.local.upload'))->toBeFalse();
});

it('scans each file once even if the job runs twice', function () {
    $file = app(FileStore::class)->store(Files::upload('a.png', Files::png()))->refresh();
    $scannedAt = $file->scanned_at;

    (new ScanStoredFile($file->id))->handle(app(FileScanner::class), app(AuditLogger::class));

    expect($file->refresh()->scanned_at?->eq($scannedAt))->toBeTrue();
});
