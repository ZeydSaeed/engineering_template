<?php

namespace App\Platform\Observability;

use InvalidArgumentException;

/**
 * Writes gauges in the Prometheus text format. A writer handed to a module
 * (MetricsSource) accepts only names with the module's prefix.
 */
final class MetricsWriter
{
    /** @var list<string> */
    private array $lines = [];

    public function __construct(private readonly string $prefix = '') {}

    public function gauge(string $name, string $help, int|float $value): void
    {
        $this->type($name, $help);
        $this->sample($name, [], $value);
    }

    public function type(string $name, string $help): void
    {
        $this->check($name);
        $this->lines[] = "# HELP {$name} {$help}";
        $this->lines[] = "# TYPE {$name} gauge";
    }

    /**
     * @param  array<string, string>  $labels
     */
    public function sample(string $name, array $labels, int|float $value): void
    {
        $this->check($name);
        $rendered = $labels === [] ? '' : '{'.implode(',', array_map(
            fn (string $key, string $label): string => $key.'="'.addcslashes($label, "\\\"\n").'"',
            array_keys($labels),
            $labels,
        )).'}';

        $this->lines[] = "{$name}{$rendered} {$value}";
    }

    /** @return list<string> */
    public function lines(): array
    {
        return $this->lines;
    }

    private function check(string $name): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1 || ! str_starts_with($name, $this->prefix)) {
            throw new InvalidArgumentException("Metric [{$name}] must be snake_case and start with [{$this->prefix}].");
        }
    }
}
