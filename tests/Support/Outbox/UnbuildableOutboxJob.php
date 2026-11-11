<?php

namespace Tests\Support\Outbox;

use App\Platform\Outbox\OutboxJob;
use RuntimeException;

/**
 * Test handler whose job cannot even be built, to exercise relay retries.
 */
class UnbuildableOutboxJob extends OutboxJob
{
    public function __construct(string $messageUuid, array $payload)
    {
        parent::__construct($messageUuid, $payload);

        throw new RuntimeException('queue unavailable');
    }

    protected function process(array $payload): void {}
}
