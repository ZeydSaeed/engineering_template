<?php

use App\Access\Permission;
use App\Access\Role;

/*
|--------------------------------------------------------------------------
| Platform
|--------------------------------------------------------------------------
|
| Cross-cutting settings owned by app/Platform. Business modules keep their
| own config files (config/<module>.php).
|
*/

return [

    /*
    | Request correlation (ADR-0009). An inbound header is reused only when it
    | matches the pattern; otherwise a UUIDv7 is generated.
    */
    'request_id' => [
        'header' => 'X-Request-Id',
        'accepted_pattern' => '/^[A-Za-z0-9._-]{8,64}$/',
    ],

    /*
    | Keys whose values are masked in every log record, at any depth. Matching
    | ignores case and treats "-" and "_" alike (ADR-0009).
    */
    'logging' => [
        'masked_keys' => [
            'password', 'password_confirmation', 'current_password', 'passwd',
            'secret', 'client_secret', 'token', 'access_token', 'refresh_token',
            'api_key', 'apikey', 'authorization', 'cookie', 'set_cookie',
            'otp', 'one_time_password', 'recovery_code', 'recovery_codes',
            'two_factor_secret', 'private_key', 'app_key',
        ],
    ],

    /*
    | Configuration checked at boot (ADR-0010). The checks run in these
    | environments only, and never for the commands below, which must work
    | while a new server is still being configured.
    */
    'environment' => [
        'enforce_in' => ['production', 'staging'],
        'exempt_commands' => [
            'about', 'config:clear', 'config:show', 'env:decrypt', 'env:encrypt',
            'key:generate', 'package:discover',
        ],
    ],

    'database' => [
        // Comma-separated database names this application must never use.
        'denied_names' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('PLATFORM_DENIED_DATABASES', '')),
        ))),

        // The runtime (DML-only) PostgreSQL role. Migrations revoke rights
        // from it where a table must stay append-only (ADR-0012, ADR-0013).
        'app_role' => env('DB_APP_ROLE'),

        // The only PostgreSQL database tests may connect to (tests/TestCase.php).
        'testing_name' => env('DB_TESTING_DATABASE', 'engineering_template_testing'),
    ],

    /*
    | Response headers (ADR-0016). Scripts run only from this origin or with
    | the per-request nonce. Styles allow 'unsafe-inline' because Radix UI and
    | toast components set inline styles at runtime.
    */
    'security' => [
        'hsts' => (bool) env('PLATFORM_HSTS', env('APP_ENV') === 'production'),
        // Start low on a new host (300, then 86400) and raise to a year.
        'hsts_max_age' => (int) env('PLATFORM_HSTS_MAX_AGE', 31536000),
        'csp' => (bool) env('PLATFORM_CSP', true),
        'csp_sources' => [
            'style-src' => ["'self'", "'unsafe-inline'", 'https://fonts.bunny.net'],
            'font-src' => ["'self'", 'https://fonts.bunny.net', 'data:'],
            'img-src' => ["'self'", 'data:', 'blob:'],
            'connect-src' => ["'self'"],
        ],

        'rate_limits' => [
            // Per e-mail address and IP, per minute.
            'login_per_minute' => (int) env('PLATFORM_LOGIN_PER_MINUTE', 5),
            // Per IP across all addresses, per hour.
            'login_per_ip_per_hour' => (int) env('PLATFORM_LOGIN_PER_IP_PER_HOUR', 100),
            'two_factor_per_minute' => (int) env('PLATFORM_TWO_FACTOR_PER_MINUTE', 5),
        ],
    ],

    /*
    | Identity defaults (ADR-0015).
    */
    'identity' => [
        // Every signed-in user must have a confirmed authenticator app or a
        // passkey before using anything but the enrolment pages.
        'require_second_factor' => (bool) env('PLATFORM_REQUIRE_SECOND_FACTOR', true),
    ],

    /*
    | Roles and permissions (ADR-0014). The application owns both enums; the
    | platform registers one Gate per permission and guards the last
    | administrator.
    */
    'access' => [
        'roles' => Role::class,
        'permissions' => Permission::class,
        'administrator_role' => 'administrator',
        'manage_permission' => 'roles.manage',
    ],

    /*
    | Audit trail (ADR-0013). The HMAC key chains each record to the previous
    | one; it is separate from APP_KEY and never stored in the database.
    */
    'audit' => [
        'hmac_key' => env('AUDIT_HMAC_KEY'),
    ],

    /*
    | Performance signals (ADR-0017).
    */
    'observability' => [
        // Server-Timing header: application and database time, query count.
        // Off in production unless asked for: timings help an attacker probe.
        'server_timing' => (bool) env('PLATFORM_SERVER_TIMING', env('APP_ENV') !== 'production'),
        // One statement slower than this is logged (SQL text only, never the
        // bound values, which may hold personal data).
        'slow_query_ms' => (int) env('PLATFORM_SLOW_QUERY_MS', 250),
        // All statements of one request together slower than this.
        'slow_request_db_ms' => (int) env('PLATFORM_SLOW_REQUEST_DB_MS', 1000),
    ],

    /*
    | Local load tests (docs/operations/performance-testing.md). The
    | synthetic-data seeder runs only locally and only on this database.
    */
    'performance' => [
        'database' => env('PERF_DATABASE', 'engineering_template'),
        'users' => (int) env('PERF_USERS', 1000),
        'audit_events' => (int) env('PERF_AUDIT_EVENTS', 50000),
    ],

];
