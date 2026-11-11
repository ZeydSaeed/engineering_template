<?php

namespace App\Platform\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Replaces the values of sensitive keys in a log record's context and extra
 * data, at any depth, before any handler writes it (ADR-0009).
 */
class MaskSensitiveData implements ProcessorInterface
{
    public const MASK = '[masked]';

    /** @var array<string, true> */
    private array $keys = [];

    /**
     * @param  list<string>  $keys
     */
    public function __construct(array $keys)
    {
        foreach ($keys as $key) {
            $this->keys[self::normalize($key)] = true;
        }
    }

    /**
     * With the keys listed in config/platform.php.
     */
    public static function fromConfig(): self
    {
        $keys = array_filter((array) config('platform.logging.masked_keys'), is_string(...));

        return new self(array_values($keys));
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->mask($record->context),
            extra: $this->mask($record->extra),
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function mask(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && isset($this->keys[self::normalize($key)])) {
                $data[$key] = self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = $this->mask($value);
            }
        }

        return $data;
    }

    private static function normalize(string $key): string
    {
        return str_replace('-', '_', strtolower($key));
    }
}
