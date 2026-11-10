<?php

namespace App\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an id that follows it into logs, audit records and
 * queued jobs (Laravel Context travels with jobs), and returns it to the
 * client. An inbound id from a proxy is reused only when well formed
 * (ADR-0009).
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) config('platform.request_id.header');
        $inbound = (string) $request->headers->get($header, '');

        $requestId = preg_match((string) config('platform.request_id.accepted_pattern'), $inbound) === 1
            ? $inbound
            : (string) Str::uuid7();

        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set($header, $requestId);

        return $response;
    }
}
