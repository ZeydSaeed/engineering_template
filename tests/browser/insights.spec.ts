import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { pageReady } from './lib/ready';

/*
| Notifications (#8), dashboard widgets (#1), reports and charts (#21) and
| tablet layout (#11), ADR-0029, in Arabic and English. The browser seeder
| gives the signed-in administrator two unread notifications.
*/

const baseURL = `http://127.0.0.1:${process.env.BROWSER_TEST_PORT ?? 8123}`;

async function open(page: Page, locale: string, path: string, width = 1366) {
    await page
        .context()
        .addCookies([{ name: 'locale', value: locale, url: baseURL }]);
    await page.setViewportSize({ width, height: 900 });
    await page.goto(path);
    await pageReady(page);
}

async function axe(page: Page, label: string) {
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

test.describe('notifications', () => {
    // Runs once: it reads the notifications for good.
    test('counted in the header, listed, read one and then all', async ({
        page,
    }) => {
        await open(page, 'en', '/dashboard');
        const link = page.locator('[data-test="notifications-link"]');
        await expect(link).toHaveAccessibleName('Notifications, 2 unread');
        await expect(page.locator('[data-test="unread-count"]')).toHaveText(
            '2',
        );

        await link.click();
        const items = page.locator('[data-slot="notifications"] li');
        await expect(items).toHaveCount(2);
        await expect(items.first()).toContainText('Setup granted you the role');
        await axe(page, 'notifications page');

        await items.first().getByRole('button').click();
        await expect(
            page.locator('[data-slot="notifications"] li[data-read="false"]'),
        ).toHaveCount(1);
        await expect(link).toHaveAccessibleName('Notifications, 1 unread');

        await page.locator('[data-test="read-all"]').click();
        await expect(
            page.locator('[data-slot="notifications"] li[data-read="false"]'),
        ).toHaveCount(0);
        await expect(page.locator('[data-test="unread-count"]')).toHaveCount(0);
    });
});

for (const locale of ['ar', 'en']) {
    test.describe(`dashboard and reports (${locale})`, () => {
        test('widgets can be hidden and come back, and the choice is kept', async ({
            page,
        }) => {
            await open(page, locale, '/dashboard');
            const widgets = page.locator(
                '[data-slot="widgets"] > [data-widget]',
            );
            await expect(widgets).toHaveCount(5);

            await page.locator('[data-test="customize-dashboard"]').click();
            await page.locator('#customize-dashboard-activity').click();
            await page.locator('[data-test="save-columns"]').click();
            await expect(page.locator('[data-widget="activity"]')).toHaveCount(
                0,
            );

            await page.reload();
            await expect(widgets).toHaveCount(4);

            await page.locator('[data-test="customize-dashboard"]').click();
            await page.locator('#customize-dashboard-activity').click();
            await page.locator('[data-test="save-columns"]').click();
            await expect(page.locator('[data-widget="activity"]')).toHaveCount(
                1,
            );
        });

        test('the chart answers keyboard and hover, has a table, and reads from the reading side', async ({
            page,
        }) => {
            await open(page, locale, '/admin/reports?days=7');

            const chart = page.locator('[data-slot="bar-chart"]').first();
            const bars = chart.locator('[data-bar]');
            await expect(bars).toHaveCount(7);
            await axe(page, 'reports');

            // Oldest day on the reading start: the right in Arabic.
            const first = await bars
                .first()
                .locator('rect')
                .first()
                .boundingBox();
            const last = await bars
                .last()
                .locator('rect')
                .first()
                .boundingBox();
            if (locale === 'ar') {
                expect(first!.x).toBeGreaterThan(last!.x);
            } else {
                expect(first!.x).toBeLessThan(last!.x);
            }

            await bars.last().focus();
            await expect(bars.last()).toHaveAttribute('aria-label', /: \S+/);
            await expect(chart.locator('[role="presentation"]')).toBeVisible();

            await chart.locator('[data-test="chart-table-toggle"]').click();
            await expect(
                chart.locator('[data-test="chart-table-toggle"]'),
            ).toHaveAttribute('aria-pressed', 'true');
            await expect(
                chart.locator('[data-slot="chart-table"] tbody tr'),
            ).toHaveCount(7);

            await page
                .locator('[data-test="period"] input[value="30"]')
                .check();
            await expect(page).toHaveURL(/days=30/);
            await expect(
                page.locator('[data-slot="chart-table"] tbody tr').first(),
            ).toBeVisible();
        });
    });
}

test.describe('tablet layout', () => {
    // No sidebar choice yet: the width decides.
    test('the sidebar starts as icons on a tablet and open on a wide screen', async ({
        browser,
    }) => {
        for (const [width, state] of [
            [820, 'collapsed'],
            [1366, 'expanded'],
        ] as const) {
            const context = await browser.newContext({
                storageState: 'storage/framework/playwright-auth.json',
                viewport: { width, height: 1000 },
            });
            const page = await context.newPage();
            await page.goto(`${baseURL}/dashboard`);
            await pageReady(page);

            await expect(
                page.locator('[data-slot="sidebar"][data-state]'),
            ).toHaveAttribute('data-state', state);
            const columns = await page
                .locator('[data-slot="widgets"]')
                .evaluate(
                    (grid) =>
                        getComputedStyle(grid).gridTemplateColumns.split(' ')
                            .length,
                );
            expect(columns).toBe(width === 820 ? 2 : 3);

            await context.close();
        }
    });
});
