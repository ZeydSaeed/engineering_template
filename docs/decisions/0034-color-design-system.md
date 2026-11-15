# ADR-0034: Colour design system (ENG-DESIGN-COLOR-001)

- Status: Accepted
- Date: 2026-11-15

## Context

ADR-0024 gave the template hand-written semantic colour tokens and a contrast
test. Changing the visual identity still meant hand-tuning dozens of OKLCH
values per theme. Thirteen raw Tailwind colours remained in `components/ui`
(outside the test's reach). The pre-paint background was a hand copy.
Nothing checked dark-mode choices, gradients or unused tokens.

## Problem

The owner should give HEX values and nothing else. A palette change must
propagate without editing components, stay accessible in every theme, and be
impossible to bypass silently. Downstream projects need the same standard
without depending on the template.

## Options

1. Keep hand-written tokens and document a procedure.
2. Adopt a third-party token pipeline (Style Dictionary or similar).
3. A small in-repo generator: plain Node with no dependency, CSS custom
   properties, Tailwind v4 `@theme`, and Pest checks.

## Decision

Option 3, specified in `docs/standards/COLOR-DESIGN-SYSTEM.md`:

- Four layers:
    - L1: generated OKLCH scales.
    - L2: generated roles per theme, chosen under contrast constraints.
    - L3: hand-written component tokens that reference L2 only.
    - Themes: L2 blocks per theme.
- Input files: `resources/design-system/palette.json` (colours, role
  overrides, project roles, gradients), `pairs.json` (declared contrast
  pairs) and `exceptions.json`.
- `scripts/design-system/palette.mjs` generates the layers, the pre-paint
  view and a report. It refuses to write CSS when a declared pair fails.
- Tailwind's default palette is switched off.
- `ColorSystemTest` and `DesignTokensTest` enforce the rules, and
  `tests/browser/colors.spec.ts` checks what the browser actually paints.

The standard is versioned (1.1.0). Downstream projects keep an independent
copy and receive changes by hand, through the update protocol in §16.

## Consequences

- A palette change is one file plus `npm run palette`. Acceptance test A
  showed 0 component files change.
- New components cannot use raw colours: CI fails.
- A colour need that no token covers means adding a role or L3 token, not a
  literal.
- Shadcn upstream updates to `components/ui` must be re-migrated to tokens,
  because the check now covers that folder.
- `destructive-foreground` changed meaning: it is now the text on a
  destructive solid, and red text uses `destructive-text`.

## Security implications

None at runtime. The generator runs at build time on repository files only.

## Performance implications

CSS grew from 13.8 kB to 14.8 kB gzipped (budget 20 kB). No runtime cost.

## Reversal or migration strategy

The generated CSS files are plain custom properties. Removing the generator
leaves them working as hand-written tokens. Evidence:
`docs/evidence/color-system/template-acceptance.md`.
