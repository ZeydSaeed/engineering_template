import { expect, test, type Page } from '@playwright/test';

/*
| Behaviour that axe cannot see (ADR-0025): dialogs and drawers keep focus,
| close on Esc and give focus back (#24); confirmations ask before acting
| (#22); fields are checked by the server while typing (#7).
*/

const baseURL = `http://127.0.0.1:${process.env.BROWSER_TEST_PORT ?? 8123}`;

async function inLocale(page: Page, locale: string, path: string) {
    await page
        .context()
        .addCookies([{ name: 'locale', value: locale, url: baseURL }]);
    await page.setViewportSize({ width: 1366, height: 900 });
    await page.goto(path);
    await page.waitForLoadState('networkidle');
}

async function focusIsInside(page: Page, selector: string): Promise<boolean> {
    return page.evaluate(
        (s) => !!document.querySelector(s)?.contains(document.activeElement),
        selector,
    );
}

for (const locale of ['ar', 'en']) {
    test.describe(`dialogs (${locale})`, () => {
        test('drawer opens from the reading end, keeps focus and gives it back', async ({
            page,
        }) => {
            await inLocale(page, locale, '/ui');
            const opener = page.locator('[data-test="open-drawer"]');
            await opener.click();

            const drawer = page.locator('[data-slot="drawer"]');
            await expect(drawer).toBeVisible();
            await expect
                .poll(() => focusIsInside(page, '[data-slot="drawer"]'))
                .toBe(true);

            const box = await drawer.boundingBox();
            const width = page.viewportSize()!.width;
            if (locale === 'ar') {
                expect(box!.x).toBeLessThan(5);
            } else {
                expect(box!.x + box!.width).toBeGreaterThan(width - 5);
            }

            for (let i = 0; i < 8; i++) {
                await page.keyboard.press('Tab');
                expect(await focusIsInside(page, '[data-slot="drawer"]')).toBe(
                    true,
                );
            }

            await page.keyboard.press('Escape');
            await expect(drawer).toBeHidden();
            await expect(opener).toBeFocused();
        });

        test('confirmation waits for the confirm button and gives focus back', async ({
            page,
        }) => {
            await inLocale(page, locale, '/ui');
            const opener = page.locator('[data-test="open-confirm"]');
            await opener.click();

            const dialog = page.locator('[data-slot="confirm-action"]');
            await expect(dialog).toBeVisible();
            await expect(page.getByRole('dialog')).toHaveAttribute(
                'aria-describedby',
                /.+/,
            );

            await page.keyboard.press('Escape');
            await expect(dialog).toBeHidden();
            await expect(opener).toBeFocused();
            await expect(page).toHaveURL(/\/ui$/);
        });

        test('account deletion asks for the password; cancel changes nothing', async ({
            page,
        }) => {
            await inLocale(page, locale, '/settings/profile');
            const opener = page.locator('[data-test="delete-user-button"]');
            await opener.click();

            const dialog = page.locator('[data-slot="confirm-action"]');
            await expect(dialog).toBeVisible();
            const password = dialog.locator('input[name="password"]');
            await expect(password).toHaveAttribute('aria-required', 'true');
            await expect(password).toHaveAccessibleName(/.+/);

            await dialog.getByRole('button', { name: /Cancel|إلغاء/ }).click();
            await expect(dialog).toBeHidden();
            await expect(opener).toBeFocused();
            await expect(page).toHaveURL(/\/settings\/profile$/);
        });

        test('a field is checked by the server while typing', async ({
            page,
        }) => {
            await inLocale(page, locale, '/settings/profile');
            const email = page.locator('input[name="email"]');
            const original = await email.inputValue();

            await email.fill('not-an-email');
            await email.blur();

            await expect(email).toHaveAttribute('aria-invalid', 'true');
            const errorId = (await email.getAttribute('aria-describedby'))!;
            const error = page.locator(`[id="${errorId}"]`);
            await expect(error).not.toBeEmpty();
            await expect(error).toHaveAttribute('aria-live', 'polite');

            await email.fill(original);
            await email.blur();
            await expect(email).not.toHaveAttribute('aria-invalid', 'true');
            await expect(error).toBeEmpty();
        });
    });
}
