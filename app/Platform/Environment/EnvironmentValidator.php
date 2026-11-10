<?php

namespace App\Platform\Environment;

/**
 * Settings that must hold before a non-local environment serves anything
 * (ADR-0010). HSTS joins this list with the security headers in Phase 2.
 */
class EnvironmentValidator
{
    /**
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        if (config('app.debug') === true) {
            $problems[] = 'APP_DEBUG must be false.';
        }

        if (blank(config('app.key'))) {
            $problems[] = 'APP_KEY must be set.';
        }

        if (config('session.secure') !== true) {
            $problems[] = 'SESSION_SECURE_COOKIE must be true.';
        }

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $problems[] = 'APP_URL must use https://.';
        }

        $database = (string) config('database.connections.'.config('database.default').'.database');
        $denied = array_map(strtolower(...), (array) config('platform.database.denied_names'));

        if (in_array(strtolower($database), $denied, true)) {
            $problems[] = "The database [{$database}] is on the deny list (PLATFORM_DENIED_DATABASES).";
        }

        return $problems;
    }

    /**
     * @throws UnsafeEnvironment
     */
    public function assertSafe(): void
    {
        $problems = $this->problems();

        if ($problems !== []) {
            throw new UnsafeEnvironment($problems);
        }
    }
}
