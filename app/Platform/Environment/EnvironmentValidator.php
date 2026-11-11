<?php

namespace App\Platform\Environment;

/**
 * Settings that must hold before a non-local environment serves anything
 * (ADR-0010, ADR-0013, ADR-0015, ADR-0016).
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

        if (config('session.encrypt') !== true) {
            $problems[] = 'SESSION_ENCRYPT must be true.';
        }

        if (filled(config('session.domain')) || config('session.path') !== '/') {
            $problems[] = 'SESSION_DOMAIN must be empty and SESSION_PATH "/" (host-only __Host- cookie).';
        }

        if (config('platform.security.hsts') !== true) {
            $problems[] = 'PLATFORM_HSTS must be true.';
        }

        if (config('platform.security.csp') !== true) {
            $problems[] = 'PLATFORM_CSP must be true.';
        }

        $auditKey = (string) config('platform.audit.hmac_key');

        if (strlen($auditKey) < 32 || $auditKey === (string) config('app.key')) {
            $problems[] = 'AUDIT_HMAC_KEY must be set, at least 32 characters, and differ from APP_KEY.';
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
