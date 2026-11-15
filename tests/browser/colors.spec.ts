import { expect, test, type Page } from '@playwright/test';
import { pageReady } from './lib/ready';

/*
| The colour design system in the browser (docs/standards/COLOR-DESIGN-
| SYSTEM.md): what components actually paint is the token they are meant to
| consume, in each theme. A probe element resolves the token the way the
| browser does, so the test holds for any palette in palette.json; the
| values are attached to the report as evidence (acceptance tests A to C).
*/

const baseURL = `http://127.0.0.1:${process.env.BROWSER_TEST_PORT ?? 8123}`;
const themes = ['light', 'dark'] as const;

async function open(page: Page, path: string, theme: string): Promise<void> {
    await page.setViewportSize({ width: 1366, height: 900 });
    await page.emulateMedia({ colorScheme: theme as 'light' | 'dark' });
    await page.context().addCookies([
        { name: 'locale', value: 'en', url: baseURL },
        { name: 'appearance', value: theme, url: baseURL },
    ]);
    await page.goto(path);
    await pageReady(page);
}

/** The computed background of a probe painted with `var(--token)`. */
async function token(page: Page, name: string): Promise<string> {
    return page.evaluate((token) => {
        const probe = document.createElement('div');

        probe.style.backgroundColor = `var(--${token})`;
        document.body.append(probe);
        const value = getComputedStyle(probe).backgroundColor;

        probe.remove();

        return value;
    }, name);
}

async function background(page: Page, selector: string): Promise<string> {
    const element = page.locator(selector).first();

    await expect(element).toBeAttached();

    return element.evaluate((el) => getComputedStyle(el).backgroundColor);
}

for (const theme of themes) {
    test(`components paint their tokens (${theme})`, async ({ page }) => {
        await open(page, '/admin/users', theme);

        await expect(page.locator('html')).toHaveClass(
            theme === 'dark' ? /\bdark\b/ : /^(?!.*\bdark\b)/,
        );

        const checks: [string, string][] = [
            ['body', 'background'],
            ['[data-sidebar="sidebar"]', 'sidebar'],
            ['[data-slot="data-table"] thead', 'table-head'],
            ['[data-slot="button"].bg-button-primary', 'button-primary'],
        ];
        const evidence: Record<string, string> = {
            primary: await token(page, 'primary'),
        };

        for (const [selector, name] of checks) {
            const expected = await token(page, name);

            expect(
                await background(page, selector),
                `${selector} paints --${name}`,
            ).toBe(expected);
            evidence[name] = expected;
        }

        // The theme decides the page: light and dark differ.
        expect(evidence.background).not.toBe('');
        test.info().annotations.push({
            type: `tokens (${theme})`,
            description: JSON.stringify(evidence),
        });
        console.log(`colours ${theme}: ${JSON.stringify(evidence)}`);
    });
}

test('the themes are designed separately, not one copy', async ({ page }) => {
    const values: Record<string, string[]> = {};

    for (const theme of themes) {
        await open(page, '/dashboard', theme);

        for (const name of ['background', 'card', 'primary', 'foreground']) {
            (values[name] ??= []).push(await token(page, name));
        }
    }

    for (const [name, [light, dark]] of Object.entries(values)) {
        expect(light, `--${name} differs between themes`).not.toBe(dark);
    }
});
