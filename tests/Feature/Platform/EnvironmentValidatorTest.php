<?php

use App\Platform\Environment\EnvironmentValidator;
use App\Platform\Environment\UnsafeEnvironment;

beforeEach(function () {
    config([
        'app.debug' => false,
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'app.url' => 'https://app.example.test',
        'session.secure' => true,
        'session.encrypt' => true,
        'session.domain' => null,
        'session.path' => '/',
        'database.default' => 'pgsql',
        'database.connections.pgsql.database' => 'template_app',
        'platform.database.denied_names' => ['legacy_live'],
        'platform.security.hsts' => true,
        'platform.security.csp' => true,
        'platform.audit.hmac_key' => base64_encode(random_bytes(32)),
        'platform.backup.encrypt' => true,
    ]);
});

it('accepts a safe configuration', function () {
    (new EnvironmentValidator)->assertSafe();

    expect((new EnvironmentValidator)->problems())->toBe([]);
});

it('reports each unsafe setting', function (array $settings, string $problem) {
    config($settings);

    expect((new EnvironmentValidator)->problems())->toContain($problem);
})->with([
    'debug on' => [['app.debug' => true], 'APP_DEBUG must be false.'],
    'empty key' => [['app.key' => ''], 'APP_KEY must be set.'],
    'insecure cookies' => [['session.secure' => false], 'SESSION_SECURE_COOKIE must be true.'],
    'unset secure cookies' => [['session.secure' => null], 'SESSION_SECURE_COOKIE must be true.'],
    'plain http url' => [['app.url' => 'http://app.example.test'], 'APP_URL must use https://.'],
    'unencrypted session' => [['session.encrypt' => false], 'SESSION_ENCRYPT must be true.'],
    'unencrypted backups' => [['platform.backup.encrypt' => false], 'BACKUP_ENCRYPT must be true.'],
    'shared cookie domain' => [['session.domain' => '.example.test'], 'SESSION_DOMAIN must be empty and SESSION_PATH "/" (host-only __Host- cookie).'],
    'cookie path' => [['session.path' => '/app'], 'SESSION_DOMAIN must be empty and SESSION_PATH "/" (host-only __Host- cookie).'],
    'hsts off' => [['platform.security.hsts' => false], 'PLATFORM_HSTS must be true.'],
    'csp off' => [['platform.security.csp' => false], 'PLATFORM_CSP must be true.'],
    'no audit key' => [['platform.audit.hmac_key' => null], 'AUDIT_HMAC_KEY must be set, at least 32 characters, and differ from APP_KEY.'],
    'short audit key' => [['platform.audit.hmac_key' => 'short'], 'AUDIT_HMAC_KEY must be set, at least 32 characters, and differ from APP_KEY.'],
    'denied database, any case' => [
        ['database.connections.pgsql.database' => 'LEGACY_LIVE'],
        'The database [LEGACY_LIVE] is on the deny list (PLATFORM_DENIED_DATABASES).',
    ],
]);

it('refuses to continue with every problem listed', function () {
    config(['app.debug' => true, 'app.url' => 'http://x.test']);

    expect(fn () => (new EnvironmentValidator)->assertSafe())
        ->toThrow(UnsafeEnvironment::class, 'APP_DEBUG must be false. APP_URL must use https://.');
});

it('does not run in the testing environment', function () {
    config(['app.debug' => true]);

    $this->get('/up')->assertOk();
});
