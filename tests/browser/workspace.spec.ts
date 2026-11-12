import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/*
| Workspace (ADR-0028): global search (#5), keyboard shortcuts (#17),
| contextual help (#27) and preferences kept on the server (#28), in
| Arabic and English.
*/

const baseURL = `http://127.0.0.1:${process.env.BROWSER_TEST_PORT ?? 8123}`;

async function open(page: Page, locale: string, path: string) {
    await page
        .context()
        .addCookies([{ name: 'locale', value: locale, url: baseURL }]);
    await page.setViewportSize({ width: 1366, height: 900 });
    await page.goto(path);
    await page.waitForLoadState('networkidle');
}

/** Sets the shortcut preference and waits until the server saved it. */
async function setShortcuts(page: Page, on: boolean) {
    const toggle = page.locator('[data-test="shortcuts-toggle"]');

    if (
        (await toggle.getAttribute('data-state')) ===
        (on ? 'checked' : 'unchecked')
    ) {
        return;
    }

    await Promise.all([
        page.waitForResponse(
            (response) =>
                response.url().endsWith('/settings/preferences') &&
                response.request().method() === 'PUT',
        ),
        toggle.click(),
    ]);
    await expect(toggle).toHaveAttribute(
        'data-state',
        on ? 'checked' : 'unchecked',
    );
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

const palette = (page: Page) => page.locator('[data-slot="command-palette"]');

for (const locale of ['ar', 'en']) {
    test.describe(`search and shortcuts (${locale})`, () => {
        test('Ctrl+K finds a page and goes there with the keyboard', async ({
            page,
        }) => {
            await open(page, locale, '/dashboard');

            await page.keyboard.press('Control+k');
            await expect(palette(page)).toBeVisible();
            const input = page.locator('[data-test="command-input"]');
            await expect(input).toBeFocused();
            await expect(input).toHaveAttribute('role', 'combobox');
            await axe(page, 'with the search open');

            await input.fill(locale === 'ar' ? 'المستخدمون' : 'Users');
            const active = await input.getAttribute('aria-activedescendant');
            expect(active).toBeTruthy();
            await expect(page.locator(`[id="${active}"]`)).toHaveAttribute(
                'aria-selected',
                'true',
            );

            await page.keyboard.press('Enter');
            await expect(page).toHaveURL(/\/admin\/users$/);
        });

        test('finds a person on the server and opens them', async ({
            page,
        }) => {
            await open(page, locale, '/dashboard');
            await page.locator('[data-test="open-search"]').click();
            await page.locator('[data-test="command-input"]').fill('Person 05');

            const person = page.locator('[data-result="users"]').first();
            await expect(person).toContainText('Person 05');
            await page.keyboard.press('ArrowDown');
            await expect(person).toHaveAttribute('aria-selected', 'true');
            await page.keyboard.press('Enter');

            await expect(page).toHaveURL(/\/admin\/users\?.*user=\d+/);
            await expect(
                page.locator('[data-slot="user-details"]'),
            ).toBeVisible();
        });

        test('Esc closes the search and gives focus back', async ({ page }) => {
            await open(page, locale, '/dashboard');
            const button = page.locator('[data-test="open-search"]');
            await button.click();
            await expect(palette(page)).toBeVisible();
            await page.keyboard.press('Escape');
            await expect(palette(page)).toBeHidden();
            await expect(button).toBeFocused();
        });

        test('single-key shortcuts work, not while typing, and can be turned off', async ({
            page,
        }) => {
            // Shortcuts on, whatever an earlier run left.
            await open(page, locale, '/settings/appearance');
            await setShortcuts(page, true);

            await open(page, locale, '/admin/users');

            await page.keyboard.press('/');
            await expect(
                page.locator('[data-test="table-search"]'),
            ).toBeFocused();
            // Typing in a field: "g d" is text, not a shortcut.
            await page.keyboard.type('gd');
            // The text became a search, not a navigation.
            await expect(page).toHaveURL(/\/admin\/users\?.*search=gd/);
            await page.locator('[data-test="table-search"]').blur();

            await page.keyboard.press('?');
            const help = page.locator('[data-slot="shortcuts-help"]');
            await expect(help).toBeVisible();
            await axe(page, 'with the shortcuts list open');
            await page.keyboard.press('Escape');

            await page.keyboard.press('g');
            await page.keyboard.press('d');
            await expect(page).toHaveURL(/\/dashboard$/);

            // Off: "?" does nothing; Ctrl+K still opens search.
            await open(page, locale, '/settings/appearance');
            await setShortcuts(page, false);
            await page.locator('body').click();
            await page.keyboard.press('?');
            await expect(
                page.locator('[data-slot="shortcuts-help"]'),
            ).toBeHidden();
            await page.keyboard.press('Control+k');
            await expect(palette(page)).toBeVisible();
            await page.keyboard.press('Escape');

            // Back on, for the other tests.
            await setShortcuts(page, true);
        });
    });

    test.describe(`preferences and help (${locale})`, () => {
        test('density and theme are kept on the server', async ({ page }) => {
            await open(page, locale, '/settings/appearance');

            await page
                .locator('[data-test="density"] input[value="compact"]')
                .check();
            await expect(page.locator('html')).toHaveAttribute(
                'data-density',
                'compact',
            );
            await page
                .locator('button', {
                    hasText: locale === 'ar' ? 'داكن' : 'Dark',
                })
                .click();

            // Without the cookies, the saved choices still apply.
            await page.context().clearCookies({ name: 'appearance' });
            await page.waitForTimeout(300);
            await page.reload();
            await expect(page.locator('html')).toHaveAttribute(
                'data-density',
                'compact',
            );
            await expect(page.locator('html')).toHaveClass(/dark/);

            // Back to the defaults.
            await page
                .locator('[data-test="density"] input[value="comfortable"]')
                .check();
            await page
                .locator('button', {
                    hasText: locale === 'ar' ? 'حسب النظام' : 'System',
                })
                .click();
            await page.waitForTimeout(300);
            await page.reload();
            await expect(page.locator('html')).toHaveAttribute(
                'data-density',
                'comfortable',
            );
        });

        test('contextual help opens in place', async ({ page }) => {
            await open(page, locale, '/settings/appearance');
            const tip = page.locator('[data-slot="help-tip"]').first();
            const toggle = tip.getByRole('button');

            await expect(toggle).toHaveAttribute('aria-expanded', 'false');
            await toggle.click();
            await expect(toggle).toHaveAttribute('aria-expanded', 'true');
            const panel = page.locator(
                `[id="${await toggle.getAttribute('aria-controls')}"]`,
            );
            await expect(panel).toBeVisible();
            await toggle.press('Enter');
            await expect(panel).toBeHidden();
        });
    });
}
