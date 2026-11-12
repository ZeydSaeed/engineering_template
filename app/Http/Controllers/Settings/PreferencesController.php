<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Preferences\WorkspacePreferences;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;

/**
 * Saves a person's workspace choices (improvement #28, ADR-0028). Language
 * and theme also go into their cookies, so the sign-in page after a
 * sign-out keeps them.
 */
class PreferencesController extends Controller
{
    public function update(Request $request, #[CurrentUser] User $user): RedirectResponse|Response
    {
        $changes = $request->validate([
            'locale' => ['sometimes', 'string', Rule::in(array_keys((array) config('platform.locales')))],
            'appearance' => ['sometimes', 'string', Rule::in(WorkspacePreferences::APPEARANCES)],
            'density' => ['sometimes', 'string', Rule::in(WorkspacePreferences::DENSITIES)],
            'shortcuts' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('shortcuts', $changes)) {
            $changes['shortcuts'] = (bool) $changes['shortcuts'];
        }

        $saved = WorkspacePreferences::save($request, $user->id, $changes);

        foreach (['locale', 'appearance'] as $key) {
            if (isset($saved[$key])) {
                Cookie::queue(cookie($key, $saved[$key], 60 * 24 * 365, httpOnly: false));
            }
        }

        // The interface saves with independent requests, so quick changes
        // never cancel each other; a plain form submission gets a redirect.
        return $request->expectsJson() ? response()->noContent() : back();
    }
}
