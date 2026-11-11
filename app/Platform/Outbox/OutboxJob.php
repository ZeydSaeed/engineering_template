<?php

namespace App\Platform\Outbox;

use App\Platform\Jobs\PlatformJob;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Handles one outbox message exactly once per consumer (ADR-0019). The
 * message is marked processed in the same transaction as the handler's own
 * database work, so a second delivery finds the mark and does nothing, and
 * a failed attempt leaves no mark and is retried.
 *
 * Work outside the database (an e-mail, an API call) cannot join that
 * transaction; such handlers must be idempotent on their own, for example by
 * sending the message uuid as an idempotency key.
 */
abstract class OutboxJob extends PlatformJob
{
    public const QUEUE = 'outbox';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public readonly string $messageUuid, public readonly array $payload)
    {
        parent::__construct();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    abstract protected function process(array $payload): void;

    final public function handle(): void
    {
        DB::transaction(function (): void {
            try {
                // A savepoint, so a duplicate does not abort the transaction.
                DB::transaction(fn () => DB::table('processed_messages')->insert([
                    'message_uuid' => $this->messageUuid,
                    'consumer' => static::class,
                    'processed_at' => now(),
                ]));
            } catch (UniqueConstraintViolationException) {
                return;
            }

            $this->process($this->payload);
        });
    }
}
