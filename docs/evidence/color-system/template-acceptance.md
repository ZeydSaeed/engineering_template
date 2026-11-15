# Colour design system: template acceptance tests (G3)

Directive ENG-DESIGN-COLOR-001, standard
`docs/standards/COLOR-DESIGN-SYSTEM.md` v1.0.0. Run on 2026-11-15, Windows 10,
PHP 8.5.12 (Herd), Node 22.13.0, Chromium headless shell 1254 from
`.tools/ms-playwright`. Base commit `b90fdbc` (G2) plus the G3 changes listed
at the end.

| Test | What                        | Result                      |
| ---- | --------------------------- | --------------------------- |
| A    | Centralized colour change   | PASS (after one fix, below) |
| B    | Component compliance        | PASS                        |
| C    | Theme switching             | PASS                        |
| D    | Accessibility (contrast)    | PASS                        |
| E    | Regression                  | PASS                        |
| F    | Future component compliance | PASS                        |

## A. Centralized colour change

Procedure (scripted, then reverted): change `colors.primary.hex` in
`resources/design-system/palette.json` from `#125CA1` to `#7B2FBE`, run
`npm run palette`, `npm run build`, the colour tests and
`tests/browser/colors.spec.ts`, then restore the four generated files and
rebuild.

**First run: FAIL.** `DesignTokensTest` reported the page background painted
before the stylesheet (`app.blade.php`) still held the old hand-copied value:
a palette change still needed one manual edit. Fixed: the generator now
writes `resources/views/design-system/prepaint.blade.php`, which
`app.blade.php` includes.

**Second run: PASS.**

- Files changed by the palette edit: `palette.json`, `palette.css`,
  `prepaint.blade.php`, `palette-report.md`. Component files changed: 0.
  `themes.css` unchanged: the roles still point at the same steps, only the
  steps' values moved.
- Built CSS: identical once token declarations are removed, so only token
  values changed, no utility or rule.
- `DesignTokensTest` + `ColorSystemTest`: 14 passed with the violet palette.
- Browser, components painting the new tokens:

| Token / element                  | Blue `#125CA1` (light)       | Violet `#7B2FBE` (light)       | Blue (dark)                 | Violet (dark)                 |
| -------------------------------- | ---------------------------- | ------------------------------ | --------------------------- | ----------------------------- |
| `--primary` = primary button     | oklch(0.47 0.13 252)         | oklch(0.49 0.209 303.5)        | oklch(0.72 0.089 252)       | oklch(0.72 0.145 303.5)       |
| `--background` = `body`          | oklch(0.98 0.009 252)        | oklch(0.98 0.011 303.5)        | oklch(0.2 0.012 252)        | oklch(0.2 0.012 303.5)        |
| `--sidebar` = sidebar            | oklch(1 0 0)                 | oklch(1 0 0)                   | oklch(0.232 0.012 252)      | oklch(0.232 0.012 303.5)      |
| `--table-head` = data-table head | oklch(0.955 0.012 252 / 0.5) | oklch(0.955 0.012 303.5 / 0.5) | oklch(0.28 0.012 252 / 0.5) | oklch(0.28 0.012 303.5 / 0.5) |

The neutrals follow the primary's hue (§5.3), so the page tint changed with
it; the light sidebar is white (neutral-0) in both.

## B. Component compliance

- `ColorSystemTest` "uses no raw colours": 0 offenders in 110 `.tsx` files
  (all of `components/ui` included) and the Blade views. 78 files use colour
  tokens; the other 32 set no colour (they inherit).
- Layer references, duplicates, missing and unused tokens: pass (8 tests).
- Browser: sidebar, table head, primary button and page background paint
  exactly their tokens (`colors.spec.ts`).

## C. Theme switching

- `colors.spec.ts` in light and dark: `<html>` gets `dark` only in dark;
  every checked element paints its token in each theme;
  `background`, `card`, `primary`, `foreground` differ between themes.
- `ColorSystemTest`: both themes define the same 44 roles; every dark role
  equal to light has a reason (explicit role, derived text, or `overlay`).

## D. Accessibility

- `DesignTokensTest`: 52 declared pairs × 2 themes, 0 failures (WCAG 2.2 AA:
  4.5:1 text, 3:1 boundaries, focus rings, chart marks).
- Generator (`palette-report.md`): 104 PASS, 0 FAIL. It refused to write CSS
  during G2 when the dark destructive boundary reached 2.91:1.
- Unsafe input: a pure yellow primary `#FFEB00` (1.16:1 as text on the light
  background) was moved to `primary-600` with the reason in the report; the
  owner's colour stays as `--palette-primary-base` and the 200 step.
- Browser: axe WCAG 2.2 A/AA, including colour contrast of rendered pages,
  in `pages.spec.ts` (test E).

## E. Regression

- `npx playwright test --project=chromium`: 299 passed (11.5 min) before the
  pre-paint fix; 299 passed (12.9 min) after it.
- `composer test`: Pint, PHPStan (0 errors), deptrac (0 violations), Pest
  347 passed, 38 skipped, 0 failed.
- `npm run check` (lint, format, `palette:check`) and `tsc --noEmit`: pass.
- `npm run budget`: pass (CSS 14.8 kB of 20 kB).
- Not run: Firefox and WebKit (CI runs them).

## F. Future component compliance

Temporary files, removed afterwards (`git status` clean of them):

1. `components/zz-color-probe.tsx`, built from tokens only (`bg-card`,
   `bg-primary-subtle`, `bg-success`, `text-destructive-text`,
   `bg-button-primary`, `hover:bg-button-primary-hover`, `ring-ring`):
   `ColorSystemTest` 9 passed.
2. `components/zz-color-violation.tsx` with `bg-blue-600`,
   `hover:text-white`, `dark:border-zinc-800/50`, `text-[#ff0000]`,
   `bg-[oklch(...)]`, `fill-[red]`, `bg-(--palette-primary-500)`,
   `style={{ color: 'red' }}`, `'#00ff00'`, `bg-unknown-token`: all ten
   reported, in two failing tests. Named arbitrary colours (`fill-[red]`) were
   added to the scan in G3.
3. A literal `#336699` in `components.css`: three tests failed (literal
   outside the generated files, L3 referencing a non-role, generator unable to
   evaluate).
4. An invalid HEX `#12G` in `palette.json`: `palette:check` exited 1 with the
   key named.

## G3 changes

- `scripts/design-system/palette.mjs`: generates the pre-paint view; hue
  families by OKLCH ranges (yellow was labelled green).
- `resources/views/app.blade.php` includes the generated
  `resources/views/design-system/prepaint.blade.php`.
- `tests/browser/colors.spec.ts` (new), `ColorSystemTest` (named arbitrary
  colours, generated view), `DesignTokensTest` (reads the generated view).
- `vite.config.ts` (formatter ignores the generated view), the standard
  (§2.1, §4, §14, §17).
