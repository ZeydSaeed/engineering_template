import { defineConfig, devices } from '@playwright/test';
import { AUTH_STATE } from './tests/browser/lib/paths';

/*
| Browser tests (ADR-0024): accessibility (axe), layout in Arabic/RTL and
| English/LTR on phone and desktop widths, and screenshots as evidence.
| They run against `php artisan serve` with the built assets and their own
| SQLite file (database/browser.sqlite), never a real database.
|
|   npm run build && npm run test:browser
*/
const port = Number(process.env.BROWSER_TEST_PORT ?? 8123);

// The server inherits these; they override .env (dotenv never replaces a
// variable that is already set).
const serverEnv = {
    APP_ENV: 'browser',
    APP_DEBUG: 'false',
    APP_URL: `http://127.0.0.1:${port}`,
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: 'database/browser.sqlite',
    SESSION_DRIVER: 'file',
    CACHE_STORE: 'array',
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'array',
    LOG_CHANNEL: 'null',
    PLATFORM_HSTS: 'false',
    // Signing in writes an audit record, which needs a key (as in phpunit.xml).
    AUDIT_HMAC_KEY: 'browser-tests-only-audit-key-not-a-secret-0123',
};

Object.assign(process.env, serverEnv);

export default defineConfig({
    testDir: 'tests/browser',
    outputDir: 'storage/framework/playwright',
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    // One retry on CI only, where a headless engine occasionally stalls
    // (seen once in WebKit: a button never reported stable). A test that
    // passes on retry is reported as flaky in the run summary, not hidden
    // (ADR-0030). Locally, none.
    retries: process.env.CI ? 1 : 0,
    // On CI, failures also become annotations on the run, readable
    // without downloading the log.
    reporter: process.env.CI ? [['github'], ['list']] : [['list']],
    globalSetup: './tests/browser/global-setup.ts',
    use: {
        baseURL: `http://127.0.0.1:${port}`,
        storageState: AUTH_STATE,
        trace: 'retain-on-failure',
        // The app honours reduced motion (app.css), so nothing is checked
        // half-faded mid-animation, on fast and slow machines alike.
        contextOptions: { reducedMotion: 'reduce' },
    },
    // One engine per run (npm run test:browser -- --project=firefox): the
    // tests change data, and each run starts from a fresh database. CI runs
    // all three (Phase 8, ADR-0030).
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
        {
            name: 'firefox',
            use: {
                ...devices['Desktop Firefox'],
                // `php artisan serve` answers one connection at a time; a
                // speculative connection Firefox opens and never uses would
                // hold every other request.
                launchOptions: {
                    firefoxUserPrefs: {
                        'network.http.speculative-parallel-limit': 0,
                        'network.predictor.enabled': false,
                        'network.prefetch-next': false,
                        'network.dns.disablePrefetch': true,
                    },
                },
            },
        },
        { name: 'webkit', use: { ...devices['Desktop Safari'] } },
    ],
    webServer: {
        command: `php artisan serve --host=127.0.0.1 --port=${port} --no-reload`,
        url: `http://127.0.0.1:${port}/up`,
        reuseExistingServer: false,
        timeout: 60_000,
        env: serverEnv,
    },
});
