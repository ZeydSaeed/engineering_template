<?php

namespace App\Platform\Jobs;

use InvalidArgumentException;

/**
 * One queue's settings from config/platform.php (ADR-0019).
 */
final class QueueSettings
{
    /**
     * @param  list<int>  $backoff
     */
    private function __construct(
        public readonly string $name,
        public readonly int $timeout,
        public readonly int $tries,
        public readonly array $backoff,
        public readonly bool $long,
    ) {}

    public static function for(string $queue): self
    {
        $settings = config("platform.queues.{$queue}");

        if (! is_array($settings)) {
            throw new InvalidArgumentException("Queue [{$queue}] is not declared in platform.queues.");
        }

        return new self(
            $queue,
            (int) $settings['timeout'],
            (int) $settings['tries'],
            array_values(array_map(intval(...), (array) $settings['backoff'])),
            (bool) $settings['long'],
        );
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return array_map(self::for(...), array_keys((array) config('platform.queues')));
    }

    /**
     * The default connection, or its *_long twin for long queues when one
     * exists (sync and other drivers have none).
     */
    public function connection(): string
    {
        $default = (string) config('queue.default');
        $long = "{$default}_long";

        return $this->long && is_array(config("queue.connections.{$long}")) ? $long : $default;
    }
}
