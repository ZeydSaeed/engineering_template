<?php

namespace Tests\Support\Outbox;

use App\Platform\Outbox\OutboxJob;
use Illuminate\Support\Facades\DB;

/**
 * Test handler: records each processed payload in the cache table, so
 * tests (and concurrency workers in other processes) can count effects.
 */
class CountingOutboxJob extends OutboxJob
{
    protected function process(array $payload): void
    {
        DB::table('cache')->insert([
            'key' => 'outbox-effect:'.$this->messageUuid,
            'value' => (string) json_encode($payload),
            'expiration' => time() + 3600,
        ]);
    }
}
