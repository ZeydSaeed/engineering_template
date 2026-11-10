<?php

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
    ],

];
