import { expect, test, type Page } from '@playwright/test';
import { pageReady } from './lib/ready';

/*
| What can be automated of the manual checks (docs/design-system/
| manual-tests.md): the whole page by keyboard (every focus visible, no
| trap), and the same in Windows high contrast (forced colors), where the
| browser drops box-shadows and backgrounds, so a focus ring drawn with a
| shadow disappears. A screen reader's speech, real devices and speech
| input still need a person.
*/

const baseURL = `http://127.0.0.1:${process.env.BROWSER_TEST_PORT ?? 8123}`;

const pages = [
    '/dashboard',
    '/admin/users',
    '/requests',
    '/requests/new',
    '/settings/profile',
    '/settings/security',
    '/notifications',
];

type Stop = { what: string; visible: boolean };

/**
 * Tab from the top of the page until focus leaves it (or comes back to
 * where it started); for each stop, whether a focus indicator is drawn.
 */
async function tabThrough(
    page: Page,
    limit = 120,
): Promise<{ stops: Stop[]; trapped: boolean }> {
    const stops: Stop[] = [];
    const seen = new Set<string>();

    // From the top of the document. (A click would set where Tab starts:
    // at (1, 1) that is the end of the page in right-to-left Arabic, and
    // Firefox would start there.)
    await page.evaluate(() => {
        (document.activeElement as HTMLElement | null)?.blur();
        window.scrollTo(0, 0);
    });

    for (let i = 0; i < limit; i++) {
        await page.keyboard.press('Tab');
        const stop = await page.evaluate(() => {
            const element = document.activeElement as HTMLElement | null;

            if (!element || element === document.body) {
                return null;
            }

            const style = getComputedStyle(element);
            const outline =
                style.outlineStyle !== 'none' &&
                parseFloat(style.outlineWidth) > 0;
            const shadow = style.boxShadow !== 'none' && style.boxShadow !== '';
            const path = [];
            for (
                let node: Element | null = element;
                node && node !== document.body;
                node = node.parentElement
            ) {
                path.unshift(
                    `${node.tagName}${node.id ? '#' + node.id : ''}:${Array.prototype.indexOf.call(node.parentElement?.children ?? [], node)}`,
                );
            }

            return {
                key: path.join('>'),
                what: `${element.tagName.toLowerCase()} "${(element.getAttribute('aria-label') ?? element.textContent ?? '').trim().slice(0, 40)}"`,
                outline,
                shadow,
            };
        });

        // Focus left the page (to the browser): the end, no trap.
        if (stop === null) {
            return { stops, trapped: false };
        }

        // Back at an element already visited: the page's tab order cycled.
        if (seen.has(stop.key)) {
            return { stops, trapped: false };
        }

        seen.add(stop.key);
        stops.push({ what: stop.what, visible: stop.outline || stop.shadow });
    }

    return { stops, trapped: true };
}

for (const locale of ['ar', 'en']) {
    test.describe(`keyboard only (${locale})`, () => {
        for (const path of pages) {
            test(`${path}: every stop shows focus, and focus can leave`, async ({
                page,
            }) => {
                await page
                    .context()
                    .addCookies([
                        { name: 'locale', value: locale, url: baseURL },
                    ]);
                await page.setViewportSize({ width: 1366, height: 900 });
                await page.goto(path);
                await pageReady(page);

                const { stops, trapped } = await tabThrough(page);

                expect(trapped, `focus trapped on ${path}`).toBe(false);
                expect(
                    stops.length,
                    `nothing to tab to on ${path}`,
                ).toBeGreaterThan(3);
                expect(
                    stops
                        .filter((stop) => !stop.visible)
                        .map((stop) => stop.what),
                    `focus not shown on ${path}`,
                ).toEqual([]);
            });
        }
    });
}

test.describe('Windows high contrast (forced colors)', () => {
    // Playwright emulates forced colors in Chromium only.
    test.skip(({ browserName }) => browserName !== 'chromium', 'Chromium only');

    for (const path of pages) {
        test(`${path}: focus is still shown`, async ({ page }) => {
            await page.emulateMedia({ forcedColors: 'active' });
            await page.setViewportSize({ width: 1366, height: 900 });
            await page.goto(path);
            await pageReady(page);

            // In forced colors the browser removes box-shadows: only an
            // outline still shows where focus is.
            const stops = await page.evaluate(
                () => matchMedia('(forced-colors: active)').matches,
            );
            expect(stops, 'forced colors emulated').toBe(true);

            const missing: string[] = [];

            for (let i = 0; i < 25; i++) {
                await page.keyboard.press('Tab');
                const stop = await page.evaluate(() => {
                    const element =
                        document.activeElement as HTMLElement | null;

                    if (!element || element === document.body) {
                        return null;
                    }

                    const style = getComputedStyle(element);

                    return {
                        what: `${element.tagName.toLowerCase()} "${(element.getAttribute('aria-label') ?? element.textContent ?? '').trim().slice(0, 40)}"`,
                        outline:
                            style.outlineStyle !== 'none' &&
                            parseFloat(style.outlineWidth) > 0,
                    };
                });

                if (stop === null) {
                    break;
                }

                if (!stop.outline) {
                    missing.push(stop.what);
                }
            }

            expect(
                [...new Set(missing)],
                `focus invisible in high contrast on ${path}`,
            ).toEqual([]);
        });
    }
});
