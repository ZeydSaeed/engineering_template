import type { Page } from '@playwright/test';

/**
 * The page has loaded and React has drawn it: the document's load event,
 * then something inside the Inertia root (which appears only once the page
 * component and its chunk are in), then the fonts.
 *
 * Not `networkidle`: in Firefox it sometimes never settles although no
 * request is open (Phase 8, ADR-0030), and Playwright advises against it.
 * Data that arrives later (deferred props, lazy panels) is waited for by
 * the assertions that need it.
 */
export async function pageReady(page: Page): Promise<void> {
    await page.waitForLoadState('load');
    await page.waitForFunction(
        () => (document.getElementById('app')?.childElementCount ?? 0) > 0,
    );
    await page.evaluate(() => document.fonts.ready);
}
