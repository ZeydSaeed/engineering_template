<?php

namespace App\Platform\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Base for every queued job (ADR-0019). The job names its queue; timeout,
 * attempts, backoff and connection come from config/platform.php, so a
 * queue's limits are changed in one place. Subclasses with a constructor
 * call parent::__construct().
 *
 * Jobs must be idempotent: a retry, or a second delivery after a worker
 * crash, must not repeat an effect.
 */
abstract class PlatformJob implements ShouldQueue
{
    use Queueable;

    /** The queue in platform.queues this job runs on. */
    public const QUEUE = 'default';

    public int $tries;

    public int $timeout;

    public function __construct()
    {
        $settings = QueueSettings::for(static::QUEUE);

        $this->onConnection($settings->connection());
        $this->onQueue($settings->name);
        $this->tries = $settings->tries;
        $this->timeout = $settings->timeout;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return QueueSettings::for(static::QUEUE)->backoff;
    }
}
