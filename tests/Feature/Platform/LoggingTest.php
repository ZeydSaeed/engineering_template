<?php

use App\Platform\Logging\MaskSensitiveData;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Level;
use Monolog\LogRecord;

it('masks sensitive keys at any depth, ignoring case and dashes', function () {
    $processor = new MaskSensitiveData(['password', 'api_key', 'authorization']);

    $record = $processor(new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'test',
        level: Level::Info,
        message: 'm',
        context: [
            'Password' => 'hunter2',
            'user' => ['email' => 'a@example.test', 'API-Key' => 'k-123'],
            'headers' => [['Authorization' => 'Bearer t']],
            'count' => 3,
        ],
        extra: ['password' => 'x'],
    ));

    expect($record->context)->toBe([
        'Password' => MaskSensitiveData::MASK,
        'user' => ['email' => 'a@example.test', 'API-Key' => MaskSensitiveData::MASK],
        'headers' => [['Authorization' => MaskSensitiveData::MASK]],
        'count' => 3,
    ])->and($record->extra)->toBe(['password' => MaskSensitiveData::MASK]);
});

it('writes JSON lines with the request id and without secrets', function () {
    $directory = storage_path('framework/testing/logs-'.Str::random(8));
    config(['logging.channels.json.path' => $directory.'/app.json.log']);

    Context::add('request_id', 'req-test-0001');
    Log::channel('json')->warning('Sign-in failed', ['email' => 'a@example.test', 'password' => 'hunter2']);

    $files = File::glob($directory.'/app.json-*.log');
    expect($files)->toHaveCount(1);

    $line = trim((string) File::get($files[0]));
    $entry = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

    expect($entry['message'])->toBe('Sign-in failed')
        ->and($entry['level_name'])->toBe('WARNING')
        ->and($entry['context']['password'])->toBe(MaskSensitiveData::MASK)
        ->and($entry['extra']['request_id'] ?? $entry['context']['request_id'] ?? null)->toBe('req-test-0001')
        ->and($line)->not->toContain('hunter2');

    File::deleteDirectory($directory);
});
