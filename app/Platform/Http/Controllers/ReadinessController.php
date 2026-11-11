<?php

namespace App\Platform\Http\Controllers;

use App\Platform\Observability\HealthChecks;
use Illuminate\Http\JsonResponse;

/**
 * Readiness probe (ADR-0023): 200 when the database, cache, files disk and
 * queue all work, 503 otherwise. Pass or fail only; never which part.
 */
class ReadinessController
{
    public function __invoke(HealthChecks $checks): JsonResponse
    {
        $healthy = $checks->healthy();

        return response()->json(['status' => $healthy ? 'ok' : 'fail'], $healthy ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
