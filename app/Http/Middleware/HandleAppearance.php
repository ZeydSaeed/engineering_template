<?php

namespace App\Http\Middleware;

use App\Platform\Preferences\WorkspacePreferences;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    /**
     * Shares the chosen theme with the root view. The cookie is not
     * encrypted, so only the known values are passed on.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // A signed-in person's saved choice first (ADR-0028), then the cookie.
        $saved = WorkspacePreferences::forRequest($request);
        $appearance = $saved['appearance'] ?? $request->cookie('appearance');

        View::share('appearance', in_array($appearance, WorkspacePreferences::APPEARANCES, true) ? $appearance : 'system');
        View::share('density', $saved['density'] ?? 'comfortable');

        return $next($request);
    }
}
