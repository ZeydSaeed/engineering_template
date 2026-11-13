<?php

namespace App\Modules\Requests\Contracts;

/**
 * The Requests module's public read API (ADR-0004, ADR-0033): what the
 * rest of the application (the dashboard) may know about requests without
 * reaching into the module's models.
 */
interface RequestsOverview
{
    /**
     * A person's own requests by status, and how many wait for their
     * decision (null when they may not decide).
     *
     * @return array{mine: array<string, int>, to_decide: int|null}
     */
    public function for(int $userId, bool $mayDecide): array;
}
