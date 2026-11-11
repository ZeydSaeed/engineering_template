<?php

namespace App\Platform\Console;

use App\Platform\Outbox\OutboxRelay;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('platform:outbox-relay {--loop : Keep relaying until stopped} {--sleep=1 : Seconds to wait when nothing was due} {--max-time=3600 : Seconds before a looping relay exits for a restart}')]
#[Description('Move due outbox messages to the queue')]
class RelayOutboxCommand extends Command
{
    public function handle(OutboxRelay $relay): int
    {
        $deadline = time() + (int) $this->option('max-time');

        do {
            $result = $relay->relay();

            if (! $this->option('loop')) {
                $this->info("Relayed {$result['dispatched']}, retrying {$result['retried']}, given up {$result['dead']}.");

                return self::SUCCESS;
            }

            if ($result['dispatched'] + $result['retried'] + $result['dead'] === 0) {
                sleep(max(1, (int) $this->option('sleep')));
            }
        } while (time() < $deadline);

        return self::SUCCESS;
    }
}
