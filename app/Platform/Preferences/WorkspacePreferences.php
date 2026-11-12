<?php

namespace App\Platform\Preferences;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A signed-in person's workspace choices (improvement #28): language, theme,
 * density and single-key shortcuts, kept on the server so they follow the
 * person between devices (ADR-0028).
 *
 * Read from the database once per session and kept in it, so pages do not
 * pay a query each; saving refreshes the session copy. Choices made on
 * another device apply from the next sign-in.
 */
final class WorkspacePreferences
{
    public const KEY = 'workspace';

    public const SESSION_KEY = 'platform.workspace';

    public const APPEARANCES = ['light', 'dark', 'system'];

    public const DENSITIES = ['comfortable', 'compact'];

    /**
     * Only the choices the person has made; empty for guests.
     *
     * @return array{locale?: string, appearance?: string, density?: string, shortcuts?: bool}
     */
    public static function forRequest(Request $request): array
    {
        $user = $request->hasSession() ? $request->user() : null;

        if ($user === null) {
            return [];
        }

        $session = $request->session();
        $cached = $session->get(self::SESSION_KEY);

        if (is_array($cached) && ($cached['user'] ?? null) === $user->getAuthIdentifier()) {
            return self::normalise((array) ($cached['values'] ?? []));
        }

        $values = self::normalise(UserPreference::read((int) $user->getAuthIdentifier(), self::KEY) ?? []);
        $session->put(self::SESSION_KEY, ['user' => $user->getAuthIdentifier(), 'values' => $values]);

        return $values;
    }

    /**
     * Merges the changes into the saved choices; unknown keys and values are
     * dropped.
     *
     * @param  array<string, mixed>  $changes
     * @return array{locale?: string, appearance?: string, density?: string, shortcuts?: bool}
     */
    public static function save(Request $request, int $userId, array $changes): array
    {
        // Saves arrive in parallel (density, then theme): read, merge and
        // write under a row lock, so one never overwrites the other.
        $values = DB::transaction(function () use ($userId, $changes): array {
            UserPreference::query()->insertOrIgnore([
                'user_id' => $userId,
                'key' => self::KEY,
                'value' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = UserPreference::query()
                ->where('user_id', $userId)
                ->where('key', self::KEY)
                ->lockForUpdate()
                ->firstOrFail();

            $values = self::normalise([...$row->value, ...$changes]);
            $row->value = $values;
            $row->save();

            return $values;
        }, 3);

        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, ['user' => $userId, 'values' => $values]);
        }

        return $values;
    }

    /**
     * @param  array<mixed>  $values
     * @return array{locale?: string, appearance?: string, density?: string, shortcuts?: bool}
     */
    public static function normalise(array $values): array
    {
        $clean = [];

        if (is_string($values['locale'] ?? null) && array_key_exists($values['locale'], (array) config('platform.locales'))) {
            $clean['locale'] = $values['locale'];
        }

        if (in_array($values['appearance'] ?? null, self::APPEARANCES, true)) {
            $clean['appearance'] = (string) $values['appearance'];
        }

        if (in_array($values['density'] ?? null, self::DENSITIES, true)) {
            $clean['density'] = (string) $values['density'];
        }

        if (is_bool($values['shortcuts'] ?? null)) {
            $clean['shortcuts'] = $values['shortcuts'];
        }

        return $clean;
    }
}
