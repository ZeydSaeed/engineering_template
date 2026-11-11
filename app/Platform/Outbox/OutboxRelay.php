<?php

namespace App\Platform\Outbox;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * Moves due outbox messages to the queue (ADR-0019). Several relays may run
 * at once: on PostgreSQL each claims its rows with FOR UPDATE SKIP LOCKED,
 * so a message is relayed by one of them. Delivery is at least once (a
 * queue push can succeed just before the relay's own commit fails);
 * OutboxJob makes the effect happen once.
 */
class OutboxRelay
{
    public function __construct(private readonly Dispatcher $bus) {}

    /**
     * @return array{dispatched: int, retried: int, dead: int}
     */
    public function relay(?int $batch = null): array
    {
        $batch ??= (int) config('platform.outbox.relay_batch');
        $result = ['dispatched' => 0, 'retried' => 0, 'dead' => 0];

        DB::transaction(function () use ($batch, &$result): void {
            $query = OutboxMessage::query()
                ->where('status', 'pending')
                ->where('available_at', '<=', now())
                ->orderBy('id')
                ->limit($batch);

            $messages = DB::getDriverName() === 'pgsql'
                ? $query->lock('for update skip locked')->get()
                : $query->lockForUpdate()->get();

            foreach ($messages as $message) {
                $result[$this->relayOne($message)]++;
            }
        });

        return $result;
    }

    /**
     * @return 'dispatched'|'retried'|'dead'
     */
    private function relayOne(OutboxMessage $message): string
    {
        try {
            // A savepoint per message: on PostgreSQL a failed statement would
            // otherwise abort the whole batch.
            DB::transaction(function () use ($message): void {
                $handler = Outbox::handlerFor($message->topic)
                    ?? throw new LogicException("No handler is registered for outbox topic [{$message->topic}].");

                // Pushed now, not after the relay commits: were it deferred,
                // a failed push after the commit would lose a message already
                // marked as dispatched.
                $this->bus->dispatch((new $handler($message->uuid, $message->payload))->beforeCommit());

                $message->forceFill(['status' => 'dispatched', 'dispatched_at' => now(), 'last_error' => null])->save();
            });

            return 'dispatched';
        } catch (Throwable $exception) {
            $attempts = $message->attempts + 1;
            $dead = $attempts >= (int) config('platform.outbox.max_attempts');
            /** @var list<int> $backoff */
            $backoff = (array) config('platform.outbox.backoff');
            $delay = $backoff[min($attempts, count($backoff)) - 1] ?? 60;

            $message->forceFill([
                'attempts' => $attempts,
                'status' => $dead ? 'dead' : 'pending',
                'available_at' => now()->addSeconds($delay),
                'last_error' => Str::limit($exception::class.': '.$exception->getMessage(), 490),
            ])->save();

            if ($dead) {
                Log::error('Outbox message could not be relayed and was given up.', [
                    'uuid' => $message->uuid,
                    'topic' => $message->topic,
                    'attempts' => $attempts,
                ]);
            }

            return $dead ? 'dead' : 'retried';
        }
    }
}
