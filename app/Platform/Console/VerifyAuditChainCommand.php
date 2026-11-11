<?php

namespace App\Platform\Console;

use App\Platform\Audit\VerifyAuditChain;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('platform:audit-verify')]
#[Description('Recompute the audit hash chain and report the first broken record')]
class VerifyAuditChainCommand extends Command
{
    /** Read by the metrics endpoint (ADR-0023). */
    public const STATUS_KEY = 'platform:audit:last-verification';

    public function handle(VerifyAuditChain $verify): int
    {
        $result = $verify->run();

        Cache::forever(self::STATUS_KEY, ['ok' => $result['broken_at'] === null, 'at' => now()->getTimestamp(), 'checked' => $result['checked']]);

        if ($result['broken_at'] !== null) {
            $this->error("Audit chain broken at record {$result['broken_at']}: {$result['reason']} ({$result['checked']} checked).");

            return self::FAILURE;
        }

        $this->info("Audit chain intact ({$result['checked']} records).");

        return self::SUCCESS;
    }
}
