<?php

namespace App\Platform\Observability;

/**
 * Database time and statement count of the current request or job. Bound
 * as a scoped instance, so it starts at zero for each one (ADR-0017).
 */
class QueryMetrics
{
    private int $count = 0;

    private float $milliseconds = 0.0;

    public function record(float $milliseconds): void
    {
        $this->count++;
        $this->milliseconds += $milliseconds;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function milliseconds(): float
    {
        return $this->milliseconds;
    }
}
