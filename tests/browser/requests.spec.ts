import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Browser, type Page } from '@playwright/test';
import { pageReady } from './lib/ready';
import { totp } from './lib/totp';

/*
| The Requests module end to end (ADR-0033), in Arabic and English: a
| person drafts a request (checked while typing), adds a file, submits it;
| an approver, signed in separately, rejects it (a reason is required); the
| requester is told and follows the notification to the request.
*/

const baseURL = `http://127.0.0.1:${process.env.BROWSER_TEST_PORT ?? 8123}`;
const pdf = Buffer.from(
    '%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n',
);

async function open(page: Page, locale: string, path: string) {
    await page
        .context()
        .addCookies([{ name: 'locale', value: locale, url: baseURL }]);
    await page.setViewportSize({ width: 1366, height: 900 });
    await page.goto(path);
    await pageReady(page);
}

async function axe(page: Page, label: string) {
    const table = page.locator('[data-slot="data-table"] table');

    if ((await table.count()) > 0) {
        await expect(table.first()).toHaveCSS('opacity', '1');
    }

    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze();
    expect(
        results.violations.map(
            (v) =>
                `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(' | ')}`,
        ),
        label,
    ).toEqual([]);
}

/** The approver, signed in with password and authenticator code. */
async function approverPage(browser: Browser, locale: string): Promise<Page> {
    const user = JSON.parse(
        readFileSync('storage/app/private/browser/user.json', 'utf8'),
    ) as { password: string; approver_email: string; approver_secret: string };
    const context = await browser.newContext({
        baseURL,
        storageState: { cookies: [], origins: [] },
        reducedMotion: 'reduce',
    });
    const page = await context.newPage();

    await open(page, locale, '/login');
    await page.fill('#email', user.approver_email);
    await page.fill('#password', user.password);
    await page.click('[data-test="login-button"]');
    await page.waitForURL('**/two-factor-challenge');

    // A code near the end of its 30 s window could expire in transit.
    if (Date.now() % 30_000 > 25_000) {
        await page.waitForTimeout(30_000 - (Date.now() % 30_000) + 500);
    }

    await page.locator('input[name="code"]').fill(totp(user.approver_secret));
    await page.locator('form button[type="submit"]').click();
    await page.waitForURL('**/dashboard');

    return page;
}

/** What the outbox relay does on a server, once (platform:outbox-relay). */
function relayOutbox() {
    execFileSync('php', ['artisan', 'platform:outbox-relay'], {
        env: process.env,
        stdio: 'ignore',
    });
}

for (const locale of ['ar', 'en']) {
    const title =
        locale === 'ar' ? 'شاشة إضافية للدعم' : 'Second screen for support';

    test.describe(`requests (${locale})`, () => {
        test('a draft is checked while typing, takes a file and is submitted', async ({
            page,
        }) => {
            await open(page, locale, '/requests');
            await axe(page, 'the list');
            // Placeholders are replaced whole: ":to" once broke ":total".
            await expect(
                page
                    .getByRole('navigation', {
                        name:
                            locale === 'ar'
                                ? 'التنقل بين الصفحات'
                                : 'Pagination',
                    })
                    .locator('p[aria-live]'),
            ).toHaveText(
                locale === 'ar' ? /عرض \d+ إلى \d+ من \d+$/ : /of \d+$/,
            );
            await page.locator('[data-test="new-request"]').click();
            await page.waitForURL('**/requests/new');

            // Checked by the server when the field is left.
            await page.locator('[name="title"]').fill('x');
            await page.locator('[name="details"]').focus();
            await expect(page.locator('[id$="-title-error"]')).toBeVisible();
            await expect(page.locator('[name="title"]')).toHaveAttribute(
                'aria-invalid',
                'true',
            );
            await axe(page, 'the form with an error');

            await page.locator('[name="title"]').fill(title);
            await page.locator('[name="details"]').fill('For the help desk.');
            await page.locator('[name="amount"]').fill('199.5');
            await page.locator('[data-test="save-request"]').click();
            await page.waitForURL(/\/requests\/[0-9a-f-]{36}$/);
            await expect(
                page.locator('[data-slot="request-status"]'),
            ).toHaveAttribute('data-status', 'draft');

            await page.locator('input[type="file"]').setInputFiles({
                name: `quote-${locale}.pdf`,
                mimeType: 'application/pdf',
                buffer: pdf,
            });
            const files = page.locator('[data-slot="attachments"]');
            await expect(files).toContainText(`quote-${locale}.pdf`);
            await expect(
                files.getByRole('link', {
                    name: new RegExp(`quote-${locale}.pdf`),
                }),
            ).toHaveAttribute('href', /\/attachments\/[0-9a-f-]{36}$/);

            await page.locator('[data-test="submit-request"]').click();
            await page.locator('[data-test="confirm-action-button"]').click();
            // Waited for inside the page while the confirmation unmounts:
            // polling it from outside, then running axe, can crash
            // Playwright's Firefox (as in data.spec.ts; ADR-0030).
            await page.waitForFunction(
                () =>
                    document
                        .querySelector('[data-slot="request-status"]')
                        ?.getAttribute('data-status') === 'submitted' &&
                    !document.querySelector('[data-slot="dialog-overlay"]'),
            );
            await expect(
                page.locator('[data-slot="request-status"]'),
            ).toHaveAttribute('data-status', 'submitted');
            // Nothing can change once submitted.
            await expect(
                page.locator('[data-test="edit-request"]'),
            ).toHaveCount(0);
            await expect(page.locator('input[type="file"]')).toHaveCount(0);
            // Checked on a fresh load of the submitted request: run right
            // after the in-place change, axe crashes Playwright's Firefox
            // (every time; Chromium and WebKit pass either way; ADR-0030).
            await page.reload();
            await pageReady(page);
            await axe(page, 'the submitted request');
        });

        test('an approver rejects it with a reason, and the requester is told', async ({
            page,
            browser,
        }) => {
            // Signing in may wait up to 30 s for a fresh authenticator code.
            test.setTimeout(90_000);
            const approver = await approverPage(browser, locale);

            await open(approver, locale, '/requests?filter[scope]=to_decide');
            await approver.getByRole('link', { name: title }).first().click();
            await approver.waitForURL(/\/requests\/[0-9a-f-]{36}$/);
            await expect(
                approver.locator('[data-slot="decision-panel"]'),
            ).toBeVisible();
            await axe(approver, 'the decision panel');

            // Without a reason the browser does not send it (the field is
            // required; the server refuses it too: RequestPagesTest).
            await approver.locator('[data-test="reject-request"]').click();
            await approver.locator('[data-test="confirm-reject"]').click();
            const note = approver.locator('[name="note"]');
            expect(
                await note.evaluate(
                    (field) =>
                        (field as HTMLTextAreaElement).validity.valueMissing,
                ),
            ).toBe(true);
            await expect(
                approver.locator('[data-slot="reject-dialog"]'),
            ).toBeVisible();
            await axe(approver, 'the rejection dialog');

            await approver
                .locator('[name="note"]')
                .fill('Not in this quarter.');
            await approver.locator('[data-test="confirm-reject"]').click();
            await approver.waitForFunction(
                () =>
                    document
                        .querySelector('[data-slot="request-status"]')
                        ?.getAttribute('data-status') === 'rejected' &&
                    !document.querySelector('[data-slot="dialog-overlay"]'),
            );
            await expect(
                approver.locator('[data-slot="request-status"]'),
            ).toHaveAttribute('data-status', 'rejected');
            await expect(
                approver.locator('[data-slot="decision"]'),
            ).toContainText('Not in this quarter.');
            await expect(
                approver.locator('[data-slot="decision-panel"]'),
            ).toHaveCount(0);
            await approver.context().close();

            // On a server the relay runs all the time; here, once.
            relayOutbox();

            await open(page, locale, '/notifications');
            const notice = page.getByRole('link', {
                name: new RegExp(title),
            });
            await expect(notice.first()).toBeVisible();
            await notice.first().click();
            await page.waitForURL(/\/requests\/[0-9a-f-]{36}$/);
            await expect(page.locator('[data-slot="decision"]')).toContainText(
                'Not in this quarter.',
            );
        });
    });
}
