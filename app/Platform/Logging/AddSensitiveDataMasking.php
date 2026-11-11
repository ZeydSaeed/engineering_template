<?php

namespace App\Platform\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/**
 * Logging channel "tap" that adds MaskSensitiveData to the channel, with the
 * keys listed in config/platform.php.
 */
class AddSensitiveDataMasking
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(MaskSensitiveData::fromConfig());
        }
    }
}
