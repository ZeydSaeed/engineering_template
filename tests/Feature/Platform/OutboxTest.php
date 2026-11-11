<?php

use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxMessage;
use App\Platform\Outbox\OutboxRelay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Outbox\CountingOutboxJob;
use Tests\Support\Outbox\UnbuildableOutboxJob;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['platform.outbox.handlers' => [
        'test.counted' => CountingOutboxJob::class,
        'test.unbuildable' => UnbuildableOutboxJob::class,
    ]]);
});

it('refuses a topic without a handler', function () {
    DB::transaction(fn () => app(Outbox::class)->record('test.unknown', []));
})->throws(LogicException::class, 'No handler is registered');

it('keeps no message when the change rolls back', function () {
    try {
        DB::transaction(function (): void {
            app(Outbox::class)->record('test.counted', ['n' => 1]);

            throw new RuntimeException('the change failed');
        });
    } catch (RuntimeException) {
    }

    expect(OutboxMessage::query()->count())->toBe(0);
});

it('relays due messages to their handlers once', function () {
    Queue::fake();

    $message = DB::transaction(fn () => app(Outbox::class)->record('test.counted', ['n' => 7]));

    expect(app(OutboxRelay::class)->relay())->toBe(['dispatched' => 1, 'retried' => 0, 'dead' => 0])
        ->and(app(OutboxRelay::class)->relay())->toBe(['dispatched' => 0, 'retried' => 0, 'dead' => 0])
        ->and($message->refresh()->status)->toBe('dispatched');

    Queue::assertPushedOn('outbox', CountingOutboxJob::class, fn (CountingOutboxJob $job): bool => $job->messageUuid === $message->uuid && $job->payload === ['n' => 7]);
    Queue::assertPushed(CountingOutboxJob::class, 1);
});

it('retries a failed relay later, then gives up and logs', function () {
    config(['platform.outbox.max_attempts' => 2, 'platform.outbox.backoff' => [30]]);
    Log::spy();

    $message = DB::transaction(fn () => app(Outbox::class)->record('test.unbuildable', ['n' => 1]));

    expect(app(OutboxRelay::class)->relay())->toBe(['dispatched' => 0, 'retried' => 1, 'dead' => 0]);

    $message->refresh();
    expect($message->attempts)->toBe(1)
        ->and($message->status)->toBe('pending')
        ->and($message->available_at->isFuture())->toBeTrue()
        ->and($message->last_error)->toContain('queue unavailable');

    // Not due yet.
    expect(app(OutboxRelay::class)->relay())->toBe(['dispatched' => 0, 'retried' => 0, 'dead' => 0]);

    $this->travel(31)->seconds();

    expect(app(OutboxRelay::class)->relay())->toBe(['dispatched' => 0, 'retried' => 0, 'dead' => 1])
        ->and($message->refresh()->status)->toBe('dead');

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $text, array $context): bool => $context['uuid'] === $message->uuid);
});

it('applies a message once however often it is delivered', function () {
    $job = new CountingOutboxJob('0193f6a0-0000-7000-8000-000000000001', ['n' => 1]);

    $job->handle();
    $job->handle();

    expect(DB::table('cache')->where('key', 'like', 'outbox-effect:%')->count())->toBe(1)
        ->and(DB::table('processed_messages')->count())->toBe(1);
});
