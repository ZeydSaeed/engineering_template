<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Platform\Access\Contracts\HoldsRoles;
use App\Platform\Http\Middleware\SetLocale;
use App\Platform\Localization\Translations;
use App\Platform\Preferences\WorkspacePreferences;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                // What the interface may offer; the server still checks
                // every request (ADR-0014).
                'permissions' => $this->permissions($request),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // No choice yet: the interface picks by screen width (#11).
            'sidebarChosen' => $request->hasCookie('sidebar_state'),
            'locale' => App::getLocale(),
            'locales' => array_keys((array) config('platform.locales')),
            'direction' => SetLocale::direction(),
            // Sent once per full page load; a language change reloads the page.
            'translations' => Inertia::once(fn (): array => Translations::for(App::getLocale())),
            // Workspace choices the interface applies itself (ADR-0028);
            // language and theme are applied by the server.
            'preferences' => $this->preferences($request),
            // The header's notification count (ADR-0029): one query on the
            // (notifiable, read_at) index.
            'notifications' => fn (): array => [
                'unread' => $request->hasSession() && $request->user() instanceof User
                    ? $request->user()->unreadNotifications()->count()
                    : 0,
            ],
        ];
    }

    /**
     * @return array{density: string, shortcuts: bool, saved: bool}
     */
    private function preferences(Request $request): array
    {
        $saved = WorkspacePreferences::forRequest($request);

        return [
            'density' => $saved['density'] ?? 'comfortable',
            'shortcuts' => $saved['shortcuts'] ?? true,
            // Signed in: changes are saved on the server, not only locally.
            'saved' => $request->hasSession() && $request->user() !== null,
        ];
    }

    /**
     * @return list<string>
     */
    private function permissions(Request $request): array
    {
        $user = $request->user();

        if (! $user instanceof HoldsRoles) {
            return [];
        }

        $permissions = [];

        foreach ($user->roles() as $role) {
            foreach ($role->permissions() as $permission) {
                $permissions[] = (string) $permission->value;
            }
        }

        $permissions = array_values(array_unique($permissions));
        sort($permissions);

        return $permissions;
    }
}
