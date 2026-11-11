<?php

namespace App\Platform\Outbox;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Records a message in the same transaction as the change it announces
 * (ADR-0019): both commit or neither does, so no event is lost when the
 * process dies after the commit and none escapes for a rolled-back change.
 * Payloads hold identifiers, not personal data.
 */
class Outbox
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(string $topic, array $payload): OutboxMessage
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Outbox messages must be recorded inside the transaction of the change they announce.');
        }

        if (self::handlerFor($topic) === null) {
            throw new LogicException("No handler is registered for outbox topic [{$topic}] (platform.outbox.handlers).");
        }

        $message = new OutboxMessage;
        $message->forceFill([
            'uuid' => (string) Str::uuid7(),
            'topic' => $topic,
            'payload' => $payload,
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
        ])->save();

        return $message;
    }

    /**
     * The job class for a topic. Looked up as a plain key: topics contain
     * dots, which config() would read as nesting.
     *
     * @return class-string<OutboxJob>|null
     */
    public static function handlerFor(string $topic): ?string
    {
        $handler = ((array) config('platform.outbox.handlers'))[$topic] ?? null;

        return is_string($handler) && is_subclass_of($handler, OutboxJob::class) ? $handler : null;
    }
}
