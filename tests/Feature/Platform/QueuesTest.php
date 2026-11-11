<?php

use App\Platform\Jobs\PlatformJob;
use App\Platform\Jobs\QueueSettings;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;

it('gives every queue a retry window longer than its longest attempt', function (string $driver) {
    config(['queue.default' => $driver]);

    foreach (QueueSettings::all() as $queue) {
        $retryAfter = (int) config("queue.connections.{$queue->connection()}.retry_after");

        expect($retryAfter)->toBeGreaterThanOrEqual($queue->timeout + 30, "{$queue->name} on {$queue->connection()}");
    }
})->with(['database', 'redis']);

it('sends long queues to the *_long connection and holds jobs until commit', function () {
    config(['queue.default' => 'database']);

    expect(QueueSettings::for('imports')->connection())->toBe('database_long')
        ->and(QueueSettings::for('notifications')->connection())->toBe('database')
        ->and(config('queue.connections.database.after_commit'))->toBeTrue()
        ->and(config('queue.connections.redis.after_commit'))->toBeTrue();
});

it('takes a job\'s queue, connection, attempts, timeout and backoff from the registry', function () {
    config(['queue.default' => 'database']);

    $job = new class extends PlatformJob
    {
        public const QUEUE = 'files';

        public function handle(): void {}
    };

    expect($job->queue)->toBe('files')
        ->and($job->connection)->toBe('database')
        ->and($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(120)
        ->and($job->backoff())->toBe([30, 120, 600]);
});

it('refuses an undeclared queue', function () {
    new class extends PlatformJob
    {
        public const QUEUE = 'nowhere';

        public function handle(): void {}
    };
})->throws(InvalidArgumentException::class, 'Queue [nowhere] is not declared');

it('logs a final job failure without its message or payload', function () {
    Log::spy();

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\SendThing');
    $job->shouldReceive('uuid')->andReturn('0000-uuid');
    $job->shouldReceive('getQueue')->andReturn('notifications');

    event(new JobFailed('database', $job, new RuntimeException('student 1234 private detail')));

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $message === 'Job failed after its last attempt.'
        && $context['job'] === 'App\\Jobs\\SendThing'
        && $context['exception'] === RuntimeException::class
        && ! str_contains((string) json_encode($context), 'private detail'));
});
