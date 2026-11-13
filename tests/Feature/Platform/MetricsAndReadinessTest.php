<?php

use App\Platform\Files\StoredFile;
use App\Platform\Outbox\OutboxMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('serves metrics to the machine itself', function () {
    $body = $this->get('/metrics')->assertOk()->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')->getContent();

    expect($body)->toContain('# TYPE platform_up gauge')
        ->toContain('platform_up 1')
        ->toContain('platform_queue_jobs{queue="notifications"} 0')
        ->toContain('platform_outbox_messages{status="dead"} 0')
        ->toContain('platform_backup_last_success_timestamp_seconds{kind="daily"}');
});

it('hides metrics from other addresses unless the token is presented', function () {
    config(['platform.observability.metrics_token' => 'scrape-token-0123456789']);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->get('/metrics')->assertNotFound();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->withToken('wrong')->get('/metrics')->assertNotFound();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->withToken('scrape-token-0123456789')->get('/metrics')->assertOk();
});

it('reports dead outbox messages and stale scans', function () {
    OutboxMessage::query()->forceCreate(['uuid' => (string) Str::uuid7(), 'topic' => 't', 'payload' => [], 'status' => 'dead', 'attempts' => 10, 'available_at' => now()]);
    StoredFile::query()->forceCreate([
        'uuid' => (string) Str::uuid7(), 'disk' => 'files', 'path' => 'x/y', 'original_name' => 'a.pdf', 'extension' => 'pdf',
        'mime_type' => 'application/pdf', 'size_bytes' => 1, 'sha256' => str_repeat('0', 64), 'status' => 'pending',
        'created_at' => now()->subHour(), 'updated_at' => now()->subHour(),
    ]);

    $body = (string) $this->get('/metrics')->getContent();

    expect($body)->toContain('platform_outbox_messages{status="dead"} 1')
        ->toContain('platform_files{status="pending"} 1')
        ->toMatch('/platform_files_oldest_pending_age_seconds (3[5-6]\d\d)/');
});

it('answers readiness with ok, and with a bare fail when a dependency breaks', function () {
    Storage::fake('files');

    $this->get('/health/ready')->assertOk()->assertExactJson(['status' => 'ok', 'release' => null]);

    config(['platform.files.disk' => 'no-such-disk']);

    $this->get('/health/ready')->assertStatus(503)->assertExactJson(['status' => 'fail', 'release' => null]);
});

it('names the release being served', function () {
    $commit = str_repeat('a1b2', 10);
    $file = base_path('RELEASE.json');
    file_put_contents($file, json_encode(['name' => 'engineering-template', 'commit' => $commit]));

    try {
        $this->get('/health/ready')->assertOk()->assertJsonPath('release', $commit);

        // Anything but a full commit SHA is not repeated.
        file_put_contents($file, json_encode(['commit' => '<script>']));
        $this->get('/health/ready')->assertJsonPath('release', null);
    } finally {
        unlink($file);
    }
});
