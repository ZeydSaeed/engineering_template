import { expect, test, type Page } from '@playwright/test';
import { pageReady } from './lib/ready';

/*
| Reflow at 320 CSS px (WCAG 1.4.10), the width of a 1280 px window at
| 400 % zoom, in Arabic and English. pages.spec.ts checks that no page
| scrolls sideways; this also finds content cut off without a scrollbar
| (the Phase 10 review found the table pagination clipped at the start
| edge in Arabic). Content inside a box that scrolls sideways on purpose,
| such as a data table, is allowed (the WCAG exception for tables).
*/

const baseURL = `http://127.0.0.1:${process.env.BROWSER_TEST_PORT ?? 8123}`;

const guestPages = ['/', '/login', '/forgot-password'];
const userPages = [
    '/dashboard',
    '/settings/profile',
    '/settings/security',
    '/settings/appearance',
    '/settings/documents',
    '/notifications',
    '/admin/users',
    '/admin/users/invite',
    '/admin/reports',
    '/requests',
    '/requests/new',
    '/requests/01960000-0000-7000-8000-000000000001',
];

/** Visible elements that stick out of the screen and cannot be scrolled to. */
async function cutOff(page: Page): Promise<string[]> {
    return page.evaluate(() => {
        const found: string[] = [];

        for (const element of document.querySelectorAll<HTMLElement>(
            'body *',
        )) {
            const box = element.getBoundingClientRect();

            if (
                box.width === 0 ||
                box.height === 0 ||
                (box.left >= -1 && box.right <= window.innerWidth + 1)
            ) {
                continue;
            }

            // Inside a box that scrolls or clips sideways on purpose (a
            // table, a closed sidebar moved off screen): not a reflow fault.
            let parent = element.parentElement;
            let contained = false;

            while (parent && parent !== document.body) {
                const overflow = getComputedStyle(parent).overflowX;

                if (overflow === 'auto' || overflow === 'scroll') {
                    contained = true;
                    break;
                }

                parent = parent.parentElement;
            }

            if (
                contained ||
                element.closest('[aria-hidden="true"], [inert]') !== null ||
                getComputedStyle(element).visibility === 'hidden'
            ) {
                continue;
            }

            const slot = element.dataset.slot ?? element.dataset.test ?? '';
            found.push(
                `${element.tagName.toLowerCase()}${slot ? `[${slot}]` : ''} ${Math.round(box.left)}..${Math.round(box.right)}`,
            );
        }

        return found.slice(0, 5);
    });
}

async function open(page: Page, path: string, locale: string) {
    await page.setViewportSize({ width: 320, height: 720 });
    await page
        .context()
        .addCookies([{ name: 'locale', value: locale, url: baseURL }]);
    await page.goto(path);
    await pageReady(page);
    await page.evaluate(() => document.fonts.ready);
}

for (const locale of ['ar', 'en']) {
    test.describe(`reflow at 320 px, guest (${locale})`, () => {
        test.use({ storageState: { cookies: [], origins: [] } });

        for (const path of guestPages) {
            test(path, async ({ page }) => {
                await open(page, path, locale);
                expect(await cutOff(page), `cut off on ${path}`).toEqual([]);
            });
        }
    });

    test.describe(`reflow at 320 px, signed in (${locale})`, () => {
        for (const path of userPages) {
            test(path, async ({ page }) => {
                await open(page, path, locale);
                expect(await cutOff(page), `cut off on ${path}`).toEqual([]);
            });
        }
    });
}
