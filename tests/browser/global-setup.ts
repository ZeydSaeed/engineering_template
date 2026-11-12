import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { chromium, type FullConfig } from '@playwright/test';
import { AUTH_STATE } from './lib/paths';
import { totp } from './lib/totp';

/**
 * A fresh browser.sqlite with one user, then one sign-in (password and
 * authenticator code) whose cookies every test reuses.
 */
export default async function globalSetup(config: FullConfig): Promise<void> {
    writeFileSync('database/browser.sqlite', '');
    execFileSync(
        'php',
        [
            'artisan',
            'migrate:fresh',
            '--force',
            '--seed',
            '--seeder=Database\\Seeders\\BrowserTestSeeder',
        ],
        { stdio: 'inherit', env: process.env },
    );

    const user = JSON.parse(
        readFileSync('storage/app/private/browser/user.json', 'utf8'),
    ) as { email: string; password: string; secret: string };
    const { baseURL } = config.projects[0].use;

    const browser = await chromium.launch();
    const page = await browser.newPage({ baseURL });

    await page
        .context()
        .addCookies([{ name: 'locale', value: 'en', url: String(baseURL) }]);
    await page.goto('/login');
    await page.fill('#email', user.email);
    await page.fill('#password', user.password);
    await page.click('[data-test="login-button"]');

    await page.waitForURL('**/two-factor-challenge');
    // A code close to the end of its 30 s window could expire in transit.
    if (Date.now() % 30_000 > 25_000) {
        await page.waitForTimeout(30_000 - (Date.now() % 30_000) + 500);
    }
    await page.locator('input[name="code"]').fill(totp(user.secret));
    await page.getByRole('button', { name: 'Continue' }).click();
    await page.waitForURL('**/dashboard');

    // The security settings ask for the password again; confirmed once, it
    // holds for the session (auth.password_timeout).
    await page.goto('/settings/security');

    if (page.url().includes('confirm-password')) {
        await page.fill('#password', user.password);
        await page.click('[data-test="confirm-password-button"]');
        await page.waitForURL('**/settings/security');
    }

    await page.context().storageState({ path: AUTH_STATE });
    await browser.close();
}
