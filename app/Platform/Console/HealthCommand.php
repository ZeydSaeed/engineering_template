<?php

namespace App\Platform\Console;

use App\Platform\Observability\HealthChecks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('platform:health')]
#[Description('Run the readiness checks and show which part fails')]
class HealthCommand extends Command
{
    public function handle(HealthChecks $checks): int
    {
        $results = $checks->run();

        foreach ($results as $check => $ok) {
            $this->line(sprintf('  %-8s %s', $check, $ok ? 'ok' : 'FAIL (see the log)'));
        }

        return in_array(false, $results, true) ? self::FAILURE : self::SUCCESS;
    }
}
