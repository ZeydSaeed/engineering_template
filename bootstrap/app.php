<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Platform\Http\Controllers\MetricsController;
use App\Platform\Http\Controllers\ReadinessController;
use App\Platform\Http\Middleware\AssignRequestId;
use App\Platform\Http\Middleware\RequireSecondFactor;
use App\Platform\Http\Middleware\SecurityHeaders;
use App\Platform\Http\Middleware\ServerTiming;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Probes outside the web group: no session, cookie or CSRF token.
        then: function (): void {
            Route::get('health/ready', ReadinessController::class)->name('health.ready');
            Route::get('metrics', MetricsController::class)->name('metrics');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Outermost, so error responses carry the id and the headers too.
        $middleware->prepend([AssignRequestId::class, ServerTiming::class, SecurityHeaders::class]);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            RequireSecondFactor::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
