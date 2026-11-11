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
        // /metrics (ADR-0023): from these addresses, or with this bearer token.
        'metrics_allowed_ips' => ['127.0.0.1', '::1'],
        'metrics_token' => env('METRICS_TOKEN'),
    ],

    /*
    | Queues by workload (ADR-0019). One worker pool per queue, so slow work
    | (scanning, PDFs, imports) never delays notifications or ordinary jobs.
    | timeout: seconds one attempt may run; tries: attempts in total;
    | backoff: seconds before each retry; long: runs on the *_long connection,
    | whose retry_after covers the timeout.
    */
    'queues' => [
        'default' => ['timeout' => 60, 'tries' => 3, 'backoff' => [10, 60, 300], 'long' => false],
        'notifications' => ['timeout' => 30, 'tries' => 5, 'backoff' => [10, 60, 300, 900], 'long' => false],
        'outbox' => ['timeout' => 60, 'tries' => 5, 'backoff' => [10, 60, 300, 900], 'long' => false],
        'files' => ['timeout' => 120, 'tries' => 3, 'backoff' => [30, 120, 600], 'long' => false],
        'pdf' => ['timeout' => 120, 'tries' => 2, 'backoff' => [30], 'long' => false],
        'imports' => ['timeout' => 900, 'tries' => 1, 'backoff' => [], 'long' => true],
        'exports' => ['timeout' => 900, 'tries' => 2, 'backoff' => [120], 'long' => true],
    ],

    /*
    | Transactional outbox (ADR-0019): topic => job class that handles it.
    | Messages are written in the same transaction as the change and relayed
    | to the queue afterwards; a failed relay is retried with these delays.
    */
    'outbox' => [
        'handlers' => [],
        'relay_batch' => 100,
        'max_attempts' => 10,
        'backoff' => [5, 30, 120, 600, 1800],
    ],

    /*
    | Uploaded files (ADR-0020). Types are an allow-list of extension =>
    | accepted content types, checked against the bytes, never the browser's
    | claim. A module may narrow it per upload.
    */
    'files' => [
        'disk' => env('FILES_DISK', 'files'),
        'max_kb' => (int) env('FILES_MAX_KB', 10240),
        'types' => [
            'pdf' => ['application/pdf'],
            'png' => ['image/png'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'webp' => ['image/webp'],
            'csv' => ['text/csv', 'text/plain'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        ],
        // Decoded image size limit (decompression bombs).
        'max_image_pixels' => 40_000_000,
        // structural, or clamav (structural checks first, then the daemon).
        'scanner' => env('FILES_SCANNER', 'structural'),
        'clamav' => [
            'host' => env('CLAMAV_HOST', '127.0.0.1'),
            'port' => (int) env('CLAMAV_PORT', 3310),
            'timeout' => (int) env('CLAMAV_TIMEOUT', 30),
        ],
        // A file still pending after this many minutes is scanned again.
        'rescan_after_minutes' => 15,
    ],

    /*
    | Outbound HTTP (ADR-0021). Calls go only to listed hosts; each service
    | has its own timeouts, retry count and circuit breaker.
    */
    'http' => [
        'services' => [
            // 'example' => ['base_url' => 'https://api.example.com', 'connect_timeout' => 3,
            //     'timeout' => 10, 'retries' => 2, 'failure_threshold' => 5, 'cooldown' => 60],
        ],
    ],

    /*
    | Backups and restore drills (ADR-0022). Each class of data is encrypted
    | to its own age recipient, so one leaked key exposes one class. Only
    | public recipients live on the server; the private identities are needed
    | for restores only and are kept offline.
    */
    'backup' => [
        'directory' => env('BACKUP_DIR', storage_path('app/private/backups')),
        'keep' => ['daily' => 7, 'weekly' => 4, 'manual' => 10],
        'recipients' => [
            // An age1… public key, or the path of a file holding one.
            'database' => env('BACKUP_RECIPIENT_DATABASE'),
            'files' => env('BACKUP_RECIPIENT_FILES'),
            'secrets' => env('BACKUP_RECIPIENT_SECRETS'),
        ],
        'tools' => [
            'age' => env('BACKUP_AGE', 'age'),
            'pg_dump' => env('BACKUP_PG_DUMP', 'pg_dump'),
            'pg_restore' => env('BACKUP_PG_RESTORE', 'pg_restore'),
            'tar' => env('BACKUP_TAR', 'tar'),
        ],
        // The read-only backup role (ADR-0012).
        'database' => [
            'username' => env('BACKUP_DB_USERNAME'),
            'password' => env('BACKUP_DB_PASSWORD'),
        ],
        // Paths relative to the application root.
        'secret_files' => ['.env'],
        'restore' => [
            // A separate, disposable database; never the application's own.
            'database' => env('RESTORE_DB_DATABASE', 'engineering_template_restore'),
            'username' => env('RESTORE_DB_USERNAME'),
            'password' => env('RESTORE_DB_PASSWORD'),
            // Private identities, needed only for a restore.
            'identities' => [
                'database' => env('BACKUP_IDENTITY_DATABASE'),
                'files' => env('BACKUP_IDENTITY_FILES'),
                'secrets' => env('BACKUP_IDENTITY_SECRETS'),
            ],
        ],
        // A backup older than this is an alert (RPO, docs/operations/recovery.md).
        'max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 26),
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
