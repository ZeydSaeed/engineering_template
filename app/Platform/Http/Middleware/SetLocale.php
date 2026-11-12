<?php

namespace App\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the interface language chosen in the "locale" cookie, when it is
 * one of platform.locales; otherwise app.locale stays (ADR-0024).
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $choice = $request->cookie('locale');

        if (is_string($choice) && array_key_exists($choice, (array) config('platform.locales'))) {
            App::setLocale($choice);
        }

        return $next($request);
    }

    public static function direction(?string $locale = null): string
    {
        $locales = (array) config('platform.locales');

        return ($locales[$locale ?? App::getLocale()] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr';
    }
}
