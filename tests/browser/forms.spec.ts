import { expect, test, type Page } from '@playwright/test';

/*
| Uploads (#14, #15) and the multi-step invitation (#6), ADR-0027, in
| Arabic and English: drop or choose files, progress, cancel, retry, the
| server's refusal; one step at a time with the server's rules, focus on
| each step's heading.
*/

const baseURL = `http://127.0.0.1:${process.env.BROWSER_TEST_PORT ?? 8123}`;

// The same minimal PDF as tests/Support/Files::pdf().
const pdf = Buffer.from(
    '%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\nstream\nBT /F1 12 Tf (Hello) Tj ET\nendstream\ntrailer << /Root 1 0 R >>\n%%EOF\n',
);

async function open(page: Page, locale: string, path: string) {
    await page
        .context()
        .addCookies([{ name: 'locale', value: locale, url: baseURL }]);
    await page.setViewportSize({ width: 1366, height: 900 });
    await page.goto(path);
    await page.waitForLoadState('networkidle');
}

const queueItem = (page: Page, name: string) =>
    page.locator('[data-slot="upload-queue"] li', { hasText: name });

for (const locale of ['ar', 'en']) {
    test.describe(`uploads (${locale})`, () => {
        test('chosen and dropped files upload and appear as documents', async ({
            page,
        }) => {
            await open(page, locale, '/settings/documents');

            await page.locator('input[type="file"]').setInputFiles({
                name: `chosen-${locale}.pdf`,
                mimeType: 'application/pdf',
                buffer: pdf,
            });
            await expect(
                queueItem(page, `chosen-${locale}.pdf`),
            ).toHaveAttribute('data-status', 'done');

            // Dropping: a DataTransfer with a file, as a browser builds it.
            const transfer = await page.evaluateHandle(
                ({ name, content }) => {
                    const data = new DataTransfer();
                    data.items.add(
                        new File([content], name, { type: 'application/pdf' }),
                    );

                    return data;
                },
                {
                    name: `dropped-${locale}.pdf`,
                    content: pdf.toString('latin1'),
                },
            );
            await page.dispatchEvent('[data-test="drop-zone"]', 'drop', {
                dataTransfer: transfer,
            });
            await expect(
                queueItem(page, `dropped-${locale}.pdf`),
            ).toHaveAttribute('data-status', 'done');

            const documents = page.locator('[data-slot="documents"]');
            await expect(documents).toContainText(`chosen-${locale}.pdf`);
            await expect(documents).toContainText(`dropped-${locale}.pdf`);
            await expect(
                documents
                    .getByRole('link', {
                        name: new RegExp(`chosen-${locale}.pdf`),
                    })
                    // The newest, if a repeated run uploaded it before.
                    .first(),
            ).toHaveAttribute('href', /\/settings\/documents\/[0-9a-f-]{36}$/);
        });

        test('shows the server refusal on the file and offers to try again', async ({
            page,
        }) => {
            await open(page, locale, '/settings/documents');

            // Named .pdf, but the bytes are a program: the server refuses it.
            await page.locator('input[type="file"]').setInputFiles({
                name: `fake-${locale}.pdf`,
                mimeType: 'application/pdf',
                buffer: Buffer.from('MZ\x90\x00 a program'),
            });

            const item = queueItem(page, `fake-${locale}.pdf`);
            await expect(item).toHaveAttribute('data-status', 'failed');
            await expect(item).toContainText(
                locale === 'ar'
                    ? 'هذا النوع من الملفات غير مقبول.'
                    : 'This type of file is not accepted.',
            );
            await expect(item.getByRole('button')).toBeVisible();
        });

        test('refuses a disallowed type before sending it', async ({
            page,
        }) => {
            await open(page, locale, '/settings/documents');
            let sent = false;
            page.on('request', (request) => {
                if (
                    request.method() === 'POST' &&
                    request.url().endsWith('/settings/documents')
                ) {
                    sent = true;
                }
            });

            await page.locator('input[type="file"]').setInputFiles({
                name: `script-${locale}.exe`,
                mimeType: 'application/octet-stream',
                buffer: Buffer.from('MZ'),
            });

            await expect(
                queueItem(page, `script-${locale}.exe`),
            ).toHaveAttribute('data-status', 'failed');
            expect(sent).toBe(false);
        });

        test('shows progress, can be cancelled and tried again', async ({
            page,
        }) => {
            await open(page, locale, '/settings/documents');
            const name = `slow-${locale}.pdf`;

            // Hold the first upload so there is time to cancel it.
            let held = true;
            await page.route('**/settings/documents', async (route) => {
                if (route.request().method() === 'POST' && held) {
                    held = false;
                    await new Promise((resolve) => setTimeout(resolve, 4000));
                }
                await route.continue().catch(() => undefined);
            });

            await page.locator('input[type="file"]').setInputFiles({
                name,
                mimeType: 'application/pdf',
                buffer: pdf,
            });

            const item = queueItem(page, name);
            await expect(item).toHaveAttribute('data-status', 'uploading');
            await expect(item.locator('progress')).toHaveAccessibleName(
                new RegExp(name),
            );

            await item.getByRole('button').click();
            await expect(item).toHaveAttribute('data-status', 'cancelled');

            await item.getByRole('button').click();
            await expect(item).toHaveAttribute('data-status', 'done');
        });
    });

    test.describe(`invitation (${locale})`, () => {
        test('goes step by step, checked by the server, and invites', async ({
            page,
        }) => {
            // Unique per run: a repeated run must not meet its own invitee.
            const email = `invited-${locale}-${Date.now()}@example.test`;
            await open(page, locale, '/admin/users/invite');

            const current = page.locator('[aria-current="step"]');
            await expect(current).toHaveAttribute('data-step', 'details');

            // Nothing filled in: the server's rules keep the step.
            await page.locator('[data-test="step-next"]').click();
            await expect(page.locator('input[name="email"]')).toHaveAttribute(
                'aria-invalid',
                'true',
            );
            await expect(current).toHaveAttribute('data-step', 'details');

            // An address already in use.
            await page.locator('input[name="name"]').fill('Invited Person');
            await page
                .locator('input[name="email"]')
                .fill('person-01@example.test');
            await page.locator('[data-test="step-next"]').click();
            await expect(page.locator('input[name="email"]')).toHaveAttribute(
                'aria-invalid',
                'true',
            );
            await expect(current).toHaveAttribute('data-step', 'details');

            await page.locator('input[name="email"]').fill(email);
            await page.locator('[data-test="step-next"]').click();
            await expect(current).toHaveAttribute('data-step', 'role');
            await expect(page.locator('#step-role')).toBeFocused();

            await page
                .getByRole('radio', {
                    name: locale === 'ar' ? 'مدقق' : 'Auditor',
                })
                .check();
            await page.locator('[data-test="step-next"]').click();
            await expect(current).toHaveAttribute('data-step', 'review');
            await expect(
                page.locator('[data-slot="invite-review"]'),
            ).toContainText(email);

            await page.locator('[data-test="step-back"]').click();
            await expect(current).toHaveAttribute('data-step', 'role');
            await page.locator('[data-test="step-next"]').click();

            await expect(current).toHaveAttribute('data-step', 'review');
            await page.locator('[data-test="step-submit"]').click();
            // Creating the account and sending the email: allow a moment.
            await expect(page).toHaveURL(/\/admin\/users$/, {
                timeout: 15_000,
            });

            await page.locator('[data-test="table-search"]').fill(email);
            await expect(
                page.locator('[data-slot="data-table"] tbody tr'),
            ).toHaveCount(1);
            await expect(
                page.locator('[data-slot="data-table"] tbody tr'),
            ).toContainText(locale === 'ar' ? 'مدقق' : 'Auditor');
        });
    });
}
