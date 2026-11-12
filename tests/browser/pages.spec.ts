import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/*
| Every page in both languages and themes, at phone and desktop widths
| (ADR-0024, improvements #10, #18, #19, #20):
|  - <html dir> follows the language;
|  - no WCAG 2.2 A/AA violation that axe can detect;
|  - nothing wider than the screen (no sideways scrolling);
|  - the sidebar sits on the reading side.
| Screenshots of the light theme go to storage/framework/screenshots.
*/

const locales = [
    { locale: 'ar', dir: 'rtl' },
    { locale: 'en', dir: 'ltr' },
] as const;
const themes = ['light', 'dark'] as const;
const viewports = {
    phone: { width: 390, height: 844 },
    tablet: { width: 820, height: 1180 },
    desktop: { width: 1366, height: 900 },
} as const;

const baseURL = `http://127.0.0.1:${process.env.BROWSER_TEST_PORT ?? 8123}`;

const guestPages = ['/', '/login', '/forgot-password'];
const userPages = [
    '/dashboard',
    '/settings/profile',
    '/settings/security',
    '/settings/appearance',
    '/ui',
    '/admin/users',
    '/admin/users/invite',
    '/settings/documents',
    '/notifications',
    '/admin/reports',
];

async function open(
    page: Page,
    path: string,
    locale: string,
    theme: string,
    viewport: keyof typeof viewports,
): Promise<void> {
    await page.setViewportSize(viewports[viewport]);
    await page.emulateMedia({ colorScheme: theme as 'light' | 'dark' });
    await page.context().addCookies([
        { name: 'locale', value: locale, url: baseURL },
        { name: 'appearance', value: theme, url: baseURL },
    ]);
    await page.goto(path);
    await page.waitForLoadState('networkidle');
    await page.evaluate(() => document.fonts.ready);
}

async function check(
    page: Page,
    path: string,
    dir: string,
    shot: string | null,
): Promise<void> {
    await expect(page.locator('html')).toHaveAttribute('dir', dir);

    const axe = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze();
    const violations = axe.violations.map(
        (v) =>
            `${v.id} (${v.impact}): ${v.help} — ${v.nodes
                .slice(0, 3)
                .map((n) => n.target.join(' '))
                .join(' | ')}`,
    );
    expect(violations, `axe on ${path}`).toEqual([]);

    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - window.innerWidth,
    );
    expect(overflow, `horizontal overflow on ${path}`).toBeLessThanOrEqual(0);

    if (shot) {
        await page.screenshot({
            path: `storage/framework/screenshots/${shot}.png`,
            fullPage: true,
        });
    }
}

function name(path: string): string {
    return path === '/' ? 'welcome' : path.slice(1).replaceAll('/', '-');
}

for (const { locale, dir } of locales) {
    for (const theme of themes) {
        for (const viewport of Object.keys(viewports) as Array<
            keyof typeof viewports
        >) {
            const shot = (path: string) =>
                theme === 'light'
                    ? `${name(path)}-${locale}-${viewport}`
                    : null;

            test.describe(`guest ${locale} ${theme} ${viewport}`, () => {
                test.use({ storageState: { cookies: [], origins: [] } });

                for (const path of guestPages) {
                    test(path, async ({ page }) => {
                        await open(page, path, locale, theme, viewport);
                        await check(page, path, dir, shot(path));
                    });
                }
            });

            test.describe(`user ${locale} ${theme} ${viewport}`, () => {
                for (const path of userPages) {
                    test(path, async ({ page }) => {
                        await open(page, path, locale, theme, viewport);
                        await check(page, path, dir, shot(path));

                        if (viewport === 'desktop') {
                            await expect(
                                page.locator(
                                    '[data-slot="sidebar"][data-side]',
                                ),
                            ).toHaveAttribute(
                                'data-side',
                                dir === 'rtl' ? 'right' : 'left',
                            );
                        }
                    });
                }
            });
        }
    }
}
