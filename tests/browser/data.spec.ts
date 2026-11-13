import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { pageReady } from './lib/ready';

/*
| The user list (ADR-0026) as a person uses it, in Arabic and English:
| server-side table (#2), columns (#3), saved views (#4), bulk actions with
| feedback (#23), details panel (#16), history (#9), breadcrumbs (#26).
| The browser seeder provides the signed-in administrator and 40 people
| (Person 01-40; every 5th an auditor) and Setup Test, 42 in all.
*/

const baseURL = `http://127.0.0.1:${process.env.BROWSER_TEST_PORT ?? 8123}`;

async function openUsers(page: Page, locale: string, query = '') {
    await page
        .context()
        .addCookies([{ name: 'locale', value: locale, url: baseURL }]);
    await page.setViewportSize({ width: 1366, height: 900 });
    await page.goto(`/admin/users${query}`);
    await pageReady(page);
}

async function axe(page: Page, label: string) {
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze();
    // A contrast failure names its colours and ratio, so a failure on CI
    // says what was measured.
    const detail = (node: { any: Array<{ data?: unknown }> }) => {
        const data = node.any[0]?.data as
            | { fgColor?: string; bgColor?: string; contrastRatio?: number }
            | undefined;

        return data?.contrastRatio
            ? ` (${data.fgColor} on ${data.bgColor}: ${data.contrastRatio})`
            : '';
    };
    expect(
        results.violations.map(
            (v) =>
                `${v.id}: ${v.nodes.map((n) => n.target.join(' ') + detail(n)).join(' | ')}`,
        ),
        label,
    ).toEqual([]);
}

const rows = (page: Page) => page.locator('[data-slot="data-table"] tbody tr');

for (const locale of ['ar', 'en']) {
    test.describe(`users table (${locale})`, () => {
        test('sorts, pages, searches and filters on the server', async ({
            page,
        }) => {
            await openUsers(page, locale);

            const nameHeader = page.locator('th:has([data-sort="name"])');
            await expect(nameHeader).toHaveAttribute('aria-sort', 'ascending');
            await expect(rows(page)).toHaveCount(25);

            await page.locator('[data-sort="name"]').click();
            await expect(nameHeader).toHaveAttribute('aria-sort', 'descending');
            await expect(rows(page).first()).toContainText('Setup Test');
            await expect(page).toHaveURL(/direction=desc/);

            await page.locator('[data-test="next-page"]').click();
            await expect(rows(page)).toHaveCount(17);
            await page.locator('[data-test="previous-page"]').click();
            await expect(rows(page)).toHaveCount(25);

            await page.locator('[data-test="table-search"]').fill('Person 07');
            await expect(rows(page)).toHaveCount(1);
            await expect(page).toHaveURL(/search=Person/);

            await page
                .locator('[data-test="table-search"]')
                .fill('nobody-by-this-name');
            await expect(
                page.locator(
                    '[data-slot="empty-state"][data-kind="no-results"]',
                ),
            ).toBeVisible();
            await page.locator('[data-slot="empty-state"] button').click();
            await expect(rows(page)).toHaveCount(25);

            await openUsers(page, locale, '?filter[role]=auditor');
            await expect(rows(page)).toHaveCount(8);
        });

        test('hides a column and keeps the choice on the server', async ({
            page,
        }) => {
            await openUsers(page, locale);
            const emailHeader = page.locator('th', {
                hasText: locale === 'ar' ? 'البريد الإلكتروني' : 'Email address',
            });
            await expect(emailHeader).toHaveCount(1);

            await page.locator('[data-test="column-settings"]').click();
            await page.locator('#column-settings-email').click();
            await page.locator('[data-test="save-columns"]').click();
            await expect(emailHeader).toHaveCount(0);

            await page.reload();
            await expect(emailHeader).toHaveCount(0);

            // Restore for the other tests.
            await page.locator('[data-test="column-settings"]').click();
            await page.locator('#column-settings-email').click();
            await page.locator('[data-test="save-columns"]').click();
            await expect(emailHeader).toHaveCount(1);
        });

        test('saves, applies and deletes a view', async ({ page }) => {
            const name = `Auditors ${locale}`;
            await openUsers(page, locale, '?filter[role]=auditor');

            await page.locator('[data-test="saved-views"]').click();
            await page.locator('[data-test="save-view"]').click();
            await page.locator('input[name="view_name"]').fill(name);
            await page.locator('[data-test="confirm-save-view"]').click();
            await expect(page.locator('input[name="view_name"]')).toBeHidden();

            await openUsers(page, locale);
            await expect(rows(page)).toHaveCount(25);
            await page.locator('[data-test="saved-views"]').click();
            await page.locator(`[data-view="${name}"]`).click();
            await expect(rows(page)).toHaveCount(8);

            // The menu has fully closed before it opens again.
            await expect(page.getByRole('menu')).toHaveCount(0);
            await page.locator('[data-test="saved-views"]').click();
            await expect(page.getByRole('menu')).toBeVisible();
            await page
                .getByRole('menuitem', {
                    name: locale === 'ar' ? 'إدارة طرق العرض' : 'Manage views',
                })
                .click();
            await test.step('delete it', async () => {
                const manage = page.locator('[data-slot="manage-views"]');
                await manage
                    .getByRole('button', { name: new RegExp(name) })
                    .click();
                await page
                    .locator('[data-test="confirm-action-button"]')
                    .click();
                await expect(
                    page.locator('[data-slot="confirm-action"]'),
                ).toBeHidden();
                // The last view gone, the manage window closes by itself.
                await expect(manage).toBeHidden();
            });

            await test.step('it is no longer offered', async () => {
                await page.locator('[data-test="saved-views"]').click();
                await expect(page.locator(`[data-view="${name}"]`)).toHaveCount(
                    0,
                );
            });
        });

        test('grants a role to several people and says who could not be changed', async ({
            page,
        }) => {
            // Two people per language, and the signed-in administrator, who
            // may not change their own roles.
            const people =
                locale === 'ar'
                    ? ['Person 01', 'Person 02']
                    : ['Person 03', 'Person 04'];
            await openUsers(page, locale);

            // The selection survives searching (the table keeps its state).
            for (const person of [...people, 'Browser Test']) {
                const search = page.locator('[data-test="table-search"]');
                await search.fill(person);
                await expect(rows(page)).toHaveCount(1);
                await page
                    .getByRole('checkbox', { name: new RegExp(person) })
                    .click();
            }

            const bar = page.locator('[data-slot="bulk-bar"]');
            await expect(bar).toContainText('3');
            await axe(page, 'with the bulk bar');

            await page.locator('[data-test="bulk-assign"]').click();
            await page.locator('[data-test="confirm-bulk"]').click();

            const result = page.locator('[data-slot="bulk-result"]');
            await expect(result).toBeVisible();
            // Measured once the confirmation has closed and the table has
            // reloaded: the overlay and the reloading table (60% opacity)
            // would fail axe's contrast check (seen in WebKit on CI).
            await expect(
                page.locator('[data-slot="dialog-overlay"]'),
            ).toHaveCount(0);
            await expect(page.locator('[aria-busy="true"]')).toHaveCount(0);
            await expect(result).toHaveAttribute('role', 'status');
            await expect(result).toContainText('Browser Test');
            await expect(result).toContainText(
                locale === 'ar'
                    ? 'لا يمكنك تغيير أدوارك بنفسك.'
                    : 'You cannot change your own roles.',
            );
            await axe(page, 'with the bulk result');
        });

        test('opens a person in a side panel with their history', async ({
            page,
        }) => {
            const person = locale === 'ar' ? 'Person 01' : 'Person 03';
            await openUsers(
                page,
                locale,
                `?search=${encodeURIComponent(person)}`,
            );

            const opener = page.locator('[data-test="open-user"]').first();
            await opener.click();

            const drawer = page.locator('[data-slot="drawer"]');
            await expect(drawer).toBeVisible();
            await expect(page).toHaveURL(/user=\d+/);
            await expect(
                drawer.locator('[data-slot="user-details"]'),
            ).toBeVisible();
            await expect(
                drawer.locator('[data-slot="timeline"] li').first(),
            ).toContainText(locale === 'ar' ? 'مُنح دور' : 'Role granted');
            await expect(drawer.locator('time').first()).toHaveAttribute(
                'datetime',
                /\d{4}-\d{2}-\d{2}T/,
            );
            await axe(page, 'with the details panel');

            await page.keyboard.press('Escape');
            // Waited for inside the page: in Playwright's Firefox, polling
            // the panel from outside while it unmounts, after axe has run,
            // crashes the test browser's page (not the app; ADR-0030).
            await page.waitForFunction(
                () => !document.querySelector('[data-slot="drawer"]'),
            );
            await expect(page).not.toHaveURL(/user=/);
            await expect(opener).toBeFocused();
        });

        test('shows where the page is and offers the link to admins', async ({
            page,
        }) => {
            await openUsers(page, locale);
            const crumbs = page.getByRole('navigation', {
                name: locale === 'ar' ? 'مسار التنقل' : 'Breadcrumb',
            });
            await expect(crumbs).toBeVisible();
            await expect(crumbs.locator('[aria-current="page"]')).toHaveText(
                locale === 'ar' ? 'المستخدمون' : 'Users',
            );
            await expect(
                page.locator('[data-slot="sidebar"]').getByRole('link', {
                    name: locale === 'ar' ? 'المستخدمون' : 'Users',
                }),
            ).toBeVisible();
        });
    });
}
