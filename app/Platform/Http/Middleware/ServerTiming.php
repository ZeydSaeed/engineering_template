<?php

namespace App\Platform\Http\Middleware;

use App\Platform\Observability\QueryMetrics;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Splits a response's server time into application and database time
 * (Server-Timing header, visible in browser developer tools and readable by
 * load tests), so a slow page can be told apart from a slow network
 * (ADR-0017).
 */
class ServerTiming
{
    public function __construct(private readonly QueryMetrics $queries) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('platform.observability.server_timing')) {
            return $response;
        }

        $start = defined('LARAVEL_START') ? (float) LARAVEL_START : (float) $request->server('REQUEST_TIME_FLOAT', microtime(true));
        $total = (microtime(true) - $start) * 1000;
        $database = $this->queries->milliseconds();

        $response->headers->set('Server-Timing', sprintf(
            'app;dur=%.1f, db;dur=%.1f;desc="%d queries"',
            max(0.0, $total - $database),
            $database,
            $this->queries->count(),
        ));

        return $response;
    }
}
