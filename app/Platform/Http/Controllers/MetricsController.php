<?php

namespace App\Platform\Http\Controllers;

use App\Platform\Observability\PlatformMetrics;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Prometheus scrape endpoint (ADR-0023). Answers the machine itself, or a
 * scraper presenting METRICS_TOKEN; anyone else gets a 404, as if it did not
 * exist.
 */
class MetricsController
{
    public function __invoke(Request $request, PlatformMetrics $metrics): Response
    {
        $token = (string) config('platform.observability.metrics_token');
        $local = in_array($request->ip(), (array) config('platform.observability.metrics_allowed_ips'), true);
        $authorised = $token !== '' && hash_equals($token, (string) $request->bearerToken());

        if (! $local && ! $authorised) {
            throw new NotFoundHttpException;
        }

        return response($metrics->render(), 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
