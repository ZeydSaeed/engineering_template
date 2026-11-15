# Color Design System

- Standard: ENG-DESIGN-COLOR-001
- Version: 1.0.0 (2026-11-15)
- Status: Accepted. What is built and tested is in the implementation status
  table (§17).
- Canonical copy: `Engineering-Template/docs/standards/COLOR-DESIGN-SYSTEM.md`.
  A downstream project keeps its own copy, and its header records the version it
  adopted (§16).
- Builds on: ADR-0024 (tokens, contrast test) and ADR-0025 (shared components).

This is the only full specification. `CLAUDE.md` and
`.cursor/rules/color-design-system.mdc` point here and do not repeat it.

## 1. Purpose and principles

The owner supplies a palette as HEX values. The system does the rest: it
analyses the palette, generates tonal scales, maps semantic roles for each
theme, derives foregrounds and gradients, and validates contrast. A palette
change is one edit to one file plus one command. No component file changes.

1. **One source per decision.** Raw colours live only in the palette
   configuration. Meaning lives only in the theme mapping. Components consume
   meaning only.
2. **Components never know the palette.** A component names a role
   (`bg-primary`, `text-muted-foreground`), never a colour.
3. **Generated, not hand-tuned.** Scales, theme files and the palette report are
   generated output, and a check fails when they drift from the configuration.
4. **Accessible by construction.** Every pairing the UI can produce is a
   declared pair, and every declared pair is contrast-tested in every theme.
5. **The owner's identity is preserved.** A supplied colour is never changed
   silently. When a colour cannot serve a role, the report says so and why, and
   another step or role is used.
6. **No new framework.** The system is Tailwind CSS v4 `@theme` custom
   properties, plain CSS files, a Node script with no new dependencies, and Pest
   tests. Existing shadcn/ui component contracts are kept.

## 2. Architecture

Four layers. Each layer may reference only the layer directly below it.

```
palette.json ──generate──▶ L1 Primitive   --palette-<family>-<step>   (palette.css, generated)
                           L2 Semantic    --<role>, per theme          (themes.css, generated)
                           L3 Component   --<component>-<part>         (components.css, hand-written)
                           L4 Theme       :root / .dark / [data-theme] selectors carrying L2
Tailwind @theme  ──exposes──▶ --color-<role> and --color-<component>-<part> as utilities
Components (.tsx) ──use──▶ utilities only (bg-primary, text-sidebar-foreground, …)
```

| Layer         | May reference                                  | May not reference             | Varies by theme |
| ------------- | ---------------------------------------------- | ----------------------------- | --------------- |
| L1 Primitive  | literal `oklch()` values                       | anything else                 | no              |
| L2 Semantic   | L1 (`var(--palette-…)`), `color-mix` of L1     | literals, L3                  | yes (L4)        |
| L3 Component  | L2 (`var(--<role>)`), `color-mix` of L2        | L1, literals                  | no              |
| UI components | Tailwind utilities generated from L2/L3 tokens | L1, literals, raw palette CSS | no              |

Because L3 is theme-agnostic and only L2 changes per theme, adding a theme
means writing one L2 block and nothing else.

### 2.1 Files

| File                                                                 | Role                                                                                      | Edited by          |
| -------------------------------------------------------------------- | ----------------------------------------------------------------------------------------- | ------------------ |
| `resources/design-system/palette.json`                               | Owner input: colours, names, role assignments, gradients                                  | owner / agent      |
| `resources/design-system/exceptions.json`                            | Approved exceptions to §13 (path, pattern, reason, approver, date)                        | with approval only |
| `scripts/design-system/palette.mjs`                                  | Analyser and generator (`npm run palette`, `npm run palette:check`)                       | engineers          |
| `resources/css/design-system/palette.css`                            | L1, generated                                                                             | generator only     |
| `resources/css/design-system/themes.css`                             | L2 per theme (L4), generated                                                              | generator only     |
| `resources/views/design-system/prepaint.blade.php`                   | The page background before the stylesheet arrives, included by `app.blade.php`, generated | generator only     |
| `resources/css/design-system/components.css`                         | L3, theme-agnostic                                                                        | engineers          |
| `resources/css/app.css`                                              | Imports the three files; the `@theme` block exposing tokens to Tailwind                   | engineers          |
| `docs/design-system/palette-report.md`                               | Generated analysis: classification, scales, contrast, warnings                            | generator only     |
| `tests/Feature/Platform/DesignTokensTest.php`, `ColorSystemTest.php` | Validation (§12)                                                                          | engineers          |

Generated files start with a `GENERATED by scripts/design-system/palette.mjs —
do not edit` header. The page background painted before the
stylesheet arrives is generated too (`prepaint.blade.php`, included by
`app.blade.php`), so a palette change needs no hand edit anywhere; a test
checks it equals `--background` of each theme (ADR-0024). Found by acceptance
test A: a hand-copied value there was the one place a palette change still
needed editing.

## 3. Palette configuration (owner input)

`resources/design-system/palette.json`:

```json
{
    "version": 1,
    "colors": {
        "primary": { "hex": "#1F5FBF", "name": "Harbour Blue" },
        "secondary": { "hex": "#0E9F8E" },
        "accent": { "hex": "#F2A900", "name": "Saffron" },
        "neutral": { "hex": null },
        "success": { "hex": null },
        "warning": { "hex": null },
        "danger": { "hex": null },
        "info": { "hex": null }
    },
    "extra": { "sand": { "hex": "#F4E3C1" } },
    "roles": { "light": {}, "dark": {} },
    "gradients": []
}
```

- `colors` holds the families every project has. `primary` is required.
  The others are optional: `null` means "derive" (§5.3).
- `extra` holds any further supporting colours. Each becomes a family.
- `name` is free text, shown in the report and in CSS comments.
- `roles` holds optional overrides of the default role mapping, per theme, e.g.
  `"brand-surface": "primary-900"`. A value is a palette reference
  (`"<family>-<step>"`, with `neutral-0` and `neutral-1000` for white and
  black), a blend (`{ "mix": ["neutral-950", 60, "neutral-900"] }`) or a
  translucent colour (`{ "alpha": ["neutral-1000", 80] }`). Anything not given
  uses the defaults in §6.
- `gradients`: see §9.

**Palette configuration and role assignment are separate decisions.** Changing
a HEX value changes the families. Changing `roles` changes which step plays
which part. Neither touches components.

## 4. Color intelligence (analysis)

For every supplied colour the generator:

1. **Validates** the input: `#RGB` or `#RRGGBB`, case-insensitive. Anything else
   stops generation with the offending key.
2. **Converts** it to OKLCH (L, C, H) and records the exact value.
3. **Classifies** it:
    - lightness: `dark` (L < 0.40), `mid`, `light` (L > 0.75)
    - chroma: `neutral` (C < 0.03), `muted` (C < 0.08), `vivid`
    - hue family by OKLCH hue: red (<40 or ≥350), orange (<75), yellow (<115),
      green (<165), teal (<215), blue (<275), purple (<320), pink (<350)
    - temperature: warm (H in [0, 110) or [330, 360)), cool otherwise
4. **Measures** its suitability against white and the generated darkest and
   lightest neutrals:
    - text on light background (≥ 4.5:1)
    - text on dark background (≥ 4.5:1)
    - can carry white or dark text (≥ 4.5:1)
    - UI boundary on either background (≥ 3:1)
5. **Flags** problems. Flags are warnings, not silent changes:
    - near-duplicate families (ΔE_OK < 0.04)
    - a brand hue within 20° of a status hue with similar chroma (e.g. a red
      primary next to `danger`), which makes status ambiguous
    - two vivid complementary colours (ΔH 150–210°, both C > 0.12) proposed as a
      text/background pair, which vibrate visually
    - a colour that can carry neither white nor dark text at 4.5:1, which is then
      limited to large text, decoration or gradients
    - an out-of-gamut step that needed chroma reduction (§5.2)

The report (`docs/design-system/palette-report.md`) lists each colour, its
classification, its suitability matrix, its flags and the role it received.
When the roles were not given in `roles`, it marks them **proposed**.

## 5. Primitive layer (L1): tonal scales

### 5.1 Steps

Each family produces 11 steps, `--palette-<family>-<step>`, with fixed OKLCH
lightness targets:

| Step | 50   | 100   | 200   | 300  | 400  | 500  | 600  | 700  | 800  | 900  | 950  |
| ---- | ---- | ----- | ----- | ---- | ---- | ---- | ---- | ---- | ---- | ---- | ---- |
| L    | 0.98 | 0.955 | 0.905 | 0.83 | 0.72 | 0.62 | 0.53 | 0.45 | 0.37 | 0.28 | 0.20 |

It also produces `--palette-<family>-base`, the exact supplied colour.

### 5.2 Generation rules

- **Hue is preserved.** Every step keeps the supplied hue H. A family derived
  for a missing colour uses a default hue (§5.3).
- **Anchor.** The step whose target L is nearest to the supplied L takes the
  exact supplied colour. Its lightness differs from the target by less than half
  a step, so lightness stays monotonic. The report names the anchor step.
- **Chroma curve.** Steps other than the anchor use
  `C(step) = C_base × k(L)`. Here k rises from 0.35 at L 0.98 to 1.0 at the
  anchor and falls to 0.6 at L 0.20. Neutral keeps a constant chroma. This keeps tints and shades from turning
  grey or neon.
- **Gamut.** Each step is mapped into sRGB by reducing chroma at constant L and
  H (binary search, tolerance 0.001). Lightness and hue are never moved to fit.
  Reductions over 0.02 are flagged.
- **Output** is `oklch(L C H)` with L and C to 3 decimals and H to 1, so the
  contrast tests can parse it.

### 5.3 Derived families

| Family    | When `hex` is `null`                                         |
| --------- | ------------------------------------------------------------ |
| neutral   | primary's hue, C = 0.012 (a tinted grey that fits the brand) |
| secondary | primary's hue −30°, C × 0.6                                  |
| accent    | primary's hue +150°, C capped at 0.15                        |
| success   | H 150, C 0.14                                                |
| warning   | H 80, C 0.16                                                 |
| danger    | H 27, C 0.19                                                 |
| info      | H 235, C 0.12                                                |

Status families keep their conventional hues even when a brand colour is close
to them. The collision is flagged instead (§4).

## 6. Semantic layer (L2)

### 6.1 Canonical roles

The role names are the shadcn/ui names the template already uses, extended. The
right-hand column maps the directive's conceptual names to them. There is **one
token per role**: no aliases, so nothing is duplicated.

| Token(s)                                                               | Conceptual role                                            |
| ---------------------------------------------------------------------- | ---------------------------------------------------------- |
| `background` / `foreground`                                            | color-background / color-text-primary                      |
| `card` / `card-foreground`                                             | color-surface                                              |
| `popover` / `popover-foreground`                                       | color-surface-elevated (menus, dialogs)                    |
| `muted` / `muted-foreground`                                           | color-surface-muted / color-text-muted                     |
| `secondary` / `secondary-foreground`                                   | color-secondary (secondary buttons, chips)                 |
| `accent` / `accent-foreground`                                         | color-accent (hover and selected rows, menu items)         |
| `primary` / `primary-foreground`                                       | color-primary / color-text-inverse on primary              |
| `primary-hover`, `primary-active`                                      | color-primary-hover / -active                              |
| `primary-subtle` / `primary-subtle-foreground`                         | color-primary-subtle (tinted panels, selected)             |
| `brand-surface` / `brand-surface-foreground` / `brand-surface-border`  | a brand-coloured large surface (e.g. a coloured sidebar)   |
| `highlight` / `highlight-foreground`                                   | a second brand emphasis surface (e.g. toolbar, table head) |
| `border`                                                               | color-border (decorative separators)                       |
| `border-strong`                                                        | color-border-strong (field and control boundaries, 3:1)    |
| `ring`                                                                 | color-focus-ring (3:1 on every adjacent surface)           |
| `success`, `warning`, `info`, `destructive` (= danger) / `-foreground` | status solids and the text on them                         |
| `success-text`, `warning-text`, `info-text`, `destructive-text`        | status-coloured text on background and card                |
| `overlay`                                                              | the scrim behind dialogs and sheets                        |
| `chart-1` … `chart-5`                                                  | data series                                                |

`X-foreground` always means "text on X". The template used to give
`destructive-foreground` the meaning "red text on the page". That meaning moves
to `destructive-text`, the usages are migrated (§18), and
`destructive-foreground` becomes the text on a destructive button.

### 6.2 Default mapping

Defaults per theme (L4), as implemented in `scripts/design-system/palette.mjs`
(`DEFAULTS`). **pick X from N** means: search family X outward from step N for
the nearest step meeting the listed contrast, taking the darker step on a tie
in light and the lighter in dark. **anchor** is the step that holds the
owner's exact colour. **auto** is the foreground rule below. `roles` in
`palette.json` may override any row.

| Role                      | Light                                                                  | Dark                                                 |
| ------------------------- | ---------------------------------------------------------------------- | ---------------------------------------------------- |
| background / foreground   | neutral-50 / neutral-950                                               | neutral-950 / neutral-50                             |
| card, popover             | neutral-0 (white)¹                                                     | mix(neutral-950 60%, neutral-900), mix(950 30%, 900) |
| card-, popover-foreground | neutral-950                                                            | neutral-50                                           |
| muted                     | neutral-100                                                            | neutral-900                                          |
| muted-foreground          | pick neutral from 700, text on background, card, muted                 | pick neutral from 400, same                          |
| secondary / accent        | secondary-100 / primary-100                                            | secondary-900 / primary-900                          |
| primary                   | pick primary from anchor, text on background and card, carries text    | pick primary from 400, same                          |
| primary-hover / -active   | one / two steps away from the foreground's lightness                   | same                                                 |
| primary-subtle            | primary-50                                                             | primary-950                                          |
| brand-surface / -border   | primary-900 / primary-800                                              | primary-950 / primary-800                            |
| highlight                 | pick accent from anchor, carries text                                  | pick accent from 400, carries text                   |
| border                    | neutral-200                                                            | neutral-900                                          |
| border-strong             | pick neutral from 500, 3:1 on background and card                      | pick neutral from 600, same                          |
| ring                      | pick primary from 600, 3:1 on background and card                      | pick primary from 300, same                          |
| destructive (danger)      | pick from 600, carries text, 3:1 on background and card                | same                                                 |
| success / warning / info  | pick from 600 / 300 / 600, carries text                                | pick from 400 / 400 / 400                            |
| status `-text`            | pick from 700, text on background and card                             | pick from 300, same                                  |
| overlay                   | neutral-1000 at 80% (theme-invariant by design)                        | same                                                 |
| chart-1…5                 | pick primary, secondary, accent, success, danger from 600, 3:1 on card | same from 400                                        |
| every other `-foreground` | auto                                                                   | auto                                                 |

¹ L1 also provides `--palette-neutral-0` (`oklch(1 0 0)`) and
`--palette-neutral-1000` (`oklch(0 0 0)`) so pure white and black are palette
references too.

**Foregrounds** are chosen, not fixed. For each solid role the generator tries
the family's 50 step, then neutral-0, then the family's 950 step, then
neutral-1000, and takes the first that reaches the required contrast (§10). If
none does, the role fails and the report names it.

### 6.3 The dark theme is designed, not inverted

- Elevation is shown by lightness, which rises with height:
  background < card < popover. Shadows are not used to show elevation in dark.
- Large surfaces use lower chroma than in light (≤ 0.08) to avoid glare.
  Vivid colour is kept for small elements: buttons, focus, status.
- Brand solids move to lighter steps, so a text colour that worked on the light
  primary is chosen again and not copied.
- Every role is listed separately in each theme block. A dark role identical to
  its light value fails the theme-consistency check (§12, check 6) unless
  `roles` sets it (or, for a `-foreground`, sets its surface) explicitly, the
  surface underneath differs, or the role is theme-invariant by design
  (`overlay`).

### 6.4 Further themes

A theme is an L2 block under its own selector (`:root` is light, `.dark` is
dark and keeps Tailwind's `dark:` variant; a further theme would use
`[data-theme='<name>']`). It must define every L2 token. Implemented: light and
dark. A further theme is added to `THEME_SELECTORS` and `DEFAULTS` in the
generator and to `DesignTokens::THEMES` in the tests (Proposed; none yet).

The theme selector must be on the root element (`<html>`), where L3 tokens are
declared: a custom property resolves `var()` on the element that declares it.

## 7. Component layer (L3)

L3 tokens specialise a role for one component. They reference L2 only, and are
theme-agnostic (one block, `:root`). They exist where a component's colour is a
decision of its own: changing it should not change every other user of the role.

| Component | Tokens (examples)                                                                                                                                           |
| --------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Button    | `--button-primary-background: var(--primary)`, `--button-primary-foreground`, `--button-primary-hover: var(--primary-hover)`                                |
| Sidebar   | `--sidebar: var(--card)` (or `var(--brand-surface)` in a branded project), `--sidebar-foreground`, `--sidebar-accent`, `--sidebar-border`, `--sidebar-ring` |
| Toolbar   | `--toolbar`, `--toolbar-foreground`                                                                                                                         |
| Table     | `--table-head`, `--table-head-foreground`, `--table-row-hover: var(--accent)`, `--table-border: var(--border)`                                              |
| Input     | `--input-background: var(--background)`, `--input-border: var(--border-strong)`, `--input-focus-ring: var(--ring)`                                          |
| Heading   | `--heading`, `--heading-foreground` (page title band)                                                                                                       |

- The names are exposed to Tailwind as `--color-<token>` in `app.css`'s
  `@theme`, so components write `bg-sidebar`, `bg-table-head` and so on.
- shadcn's existing names (`--sidebar-*`, `--input`) are kept as the L3 names,
  so upstream component code keeps working. `--input` becomes
  `var(--border-strong)`.
- Ordinary components that have no L3 tokens use L2 utilities directly. That is
  allowed: a component does not need its own tokens to be compliant.
- A project gives its components its own look by changing L3 references (e.g.
  sidebar → brand-surface) or `roles`, never by putting colours in components.

## 8. Blending rules

- Blends are made with `color-mix(in oklch, A p%, B)` between tokens of the
  layer below, never between literals. Do not mix in sRGB: it greys out the
  middle.
- Transparency is a blend with `transparent` (`color-mix(in oklch, var(--x) 35%,
  transparent)`) or a Tailwind opacity modifier on a token (`bg-primary/10`).
- A blended colour that carries text, or bounds a control, must be a declared
  pair (§10). Its contrast is measured after compositing over the surface it is
  declared on.
- Hover is a separate token (`primary-hover`) or an opacity modifier on the same
  token, applied the same way across one component family.

## 9. Gradient governance

Gradients are tokens, declared in `palette.json`:

```json
"gradients": [
    { "name": "toolbar", "angle": 90, "stops": ["accent-400", "accent-500"],
      "foreground": "highlight-foreground", "themes": ["light", "dark"] }
]
```

- The generator emits `--gradient-<name>` into each listed theme block, built
  from palette references only. `components.css` exposes each as a
  `bg-gradient-<name>` utility.
- **Every stop is contrast-checked** against the declared foreground at the
  text threshold. A gradient that fails is not emitted, and the report says why.
- At most 3 stops. Adjacent stops differ in L by no more than 0.15, so text never
  crosses an unreadable band.
- Each gradient sits over a solid fallback token (`background-color`), so
  printing and forced-colors mode stay readable.
- Literal colours inside `linear-gradient()` are forbidden outside generated
  files (§13).

## 10. Accessibility (WCAG 2.2 AA)

| Pair kind                                                              | Minimum                                               | WCAG       |
| ---------------------------------------------------------------------- | ----------------------------------------------------- | ---------- |
| Normal text on its surface                                             | 4.5:1                                                 | 1.4.3      |
| Large text (≥ 24 px, or ≥ 18.66 px bold) only                          | 3:1                                                   | 1.4.3      |
| Control boundaries, focus rings, chart marks, icons that carry meaning | 3:1                                                   | 1.4.11     |
| Disabled controls                                                      | exempt (shown by `opacity-50`, never by a new colour) | 1.4.3 note |

- Contrast is computed exactly as `DesignTokensTest` already does: OKLCH to
  linear sRGB, clipped, then the WCAG relative luminance. Pairs with alpha are
  composited over their declared surface first.
- **Declared pairs** are listed in `resources/design-system/pairs.json`
  (`fg`, `bg`, `min`, and `over` for a translucent `bg`). The generator refuses
  to write CSS that fails one, and `DesignTokensTest` checks them per theme.
  The list covers:
    - every `X` / `X-foreground`
    - every `X-text` on `background` and on `card`
    - `muted-foreground` on `background`, `card` and `muted`
    - `primary` used as link text on `background`
    - `border-strong`, `input` and `ring` on every surface they touch
    - every gradient stop
    - every L3 text pair

    A new pairing in a component means a new entry in `pairs.json`, in the
    same change. A test also requires a pair for every `X-foreground` on `X`
    and every `X-text` on `background`.

- **Colour is never the only signal.** Status badges, validation errors, chart
  series and table states also carry text, an icon or a pattern. Links in body
  text are underlined or otherwise distinguished without colour.
- Forced-colors (Windows high contrast) mode keeps the existing outline rule in
  `app.css`.
- Browser checks (axe WCAG 2.2 A/AA, `tests/browser`) remain the final check of
  real rendered combinations.

## 11. Implementation conventions

- **Allowed in components:** utilities named after L2/L3 tokens (`bg-card`,
  `text-destructive-text`, `border-border-strong`, `ring-ring`,
  `bg-gradient-toolbar`), opacity modifiers on them, and `transparent`,
  `current`, `inherit`.
- **Forbidden in components (`.tsx`, Blade views):**
    - Tailwind palette colours (`bg-blue-600`, `text-white`, `bg-black/80`, …)
    - arbitrary colour values (`bg-[#123456]`, `text-[oklch(…)]`,
      `bg-[var(--palette-…)]`)
    - inline `style` colours
    - `--palette-*` references
- **Forbidden in CSS outside `design-system/`:** colour literals (hex, `rgb()`,
  `hsl()`, `oklch()`), except `app.css`'s forced-colors system colours
  (`Highlight`), and the blade pre-paint colour, which a test pins to the token.
- `dark:` variants for colour are not needed and are discouraged: the token
  already changes with the theme. `dark:` remains fine for non-colour
  differences.
- New tokens are added in this order: L2 role in the generator defaults (both
  themes), then the `@theme` exposure, then the declared pairs in the test, then
  use. A token that is exposed but never used fails the unused-token check.

## 12. Automated validation

| #   | Check                 | How                                                                                                                                                                                                                                                                                                                                                         |
| --- | --------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Hardcoded UI colours  | `ColorSystemTest`: every `.tsx` (including `components/ui`) and Blade view, for §11's forbidden forms (palette utilities with any variant, arbitrary colour values, `--palette-*`, inline style colours, colour strings); `app.css` and `components.css` hold no colour literal; `exceptions.json` is the only way out                                      |
| 2   | Duplicate tokens      | `ColorSystemTest`: no name twice in a block (L1, each L2 theme, L3, `@theme`), and L1, L2 and L3 never share a name                                                                                                                                                                                                                                         |
| 3   | Missing tokens        | `ColorSystemTest`/`DesignTokensTest`: every `var()` in the layers resolves; every `@theme` colour exposes an existing role or L3 token under its own name; every pair names existing tokens; every `bg-`, `fill-` and `stroke-` colour utility used resolves (`text-` and `border-` utilities are not checked: their non-colour forms are too many to list) |
| 4   | Unused tokens         | `ColorSystemTest`: every L3 token is used by a component or by CSS other than its definition. L2 roles are a contract every project keeps, so an idle role (e.g. `highlight` in the template) is allowed                                                                                                                                                    |
| 5   | Layer references      | L1 has literals only; L2 references L1 only; L3 references L2 only (§2)                                                                                                                                                                                                                                                                                     |
| 6   | Theme consistency     | `ColorSystemTest`: the same role set in every theme; a dark role equal to light needs a reason (§6.3)                                                                                                                                                                                                                                                       |
| 7   | Contrast              | `DesignTokensTest`: every declared pair (§10), every theme                                                                                                                                                                                                                                                                                                  |
| 8   | Component compliance  | Check 1 covers every component, `components/ui` included, and names each offending file                                                                                                                                                                                                                                                                     |
| 9   | Palette configuration | `npm run palette:check` (part of `npm run check`) and `ColorSystemTest`: valid JSON and HEX, references to existing steps, every declared pair passes, generated files byte-identical to a fresh generation                                                                                                                                                 |
| 10  | Visual regression     | Playwright `tests/browser` (axe and screenshots on every page × theme × direction × width). Pixel-baseline comparison is **Planned**: the screenshots are kept as CI artifacts but not compared yet                                                                                                                                                         |

Checks 1–9 run in `composer test` (Pest) locally and in CI's `ci` and
`pgsql` jobs; check 9 also runs in `composer ci:check` through `npm run check`.

## 13. Exceptions and approval

An exception is an entry in `resources/design-system/exceptions.json`:

```json
{
    "path": "resources/js/components/two-factor-setup-modal.tsx",
    "pattern": "bg-white",
    "reason": "A QR code needs a white quiet zone in every theme to scan.",
    "approved_by": "owner",
    "date": "2026-11-15"
}
```

- The test strips exactly that pattern in exactly that file and nothing else. A
  stale entry (pattern no longer present) fails the test.
- Adding an exception requires the owner's approval, recorded in the entry.
- Generated files and `components.css` are not exceptions. They are the system.

## 14. Changing the palette (procedure)

1. Edit `resources/design-system/palette.json`: HEX values, optional names,
   optional `roles` and `gradients`.
2. Run `npm run palette`. It regenerates `palette.css`, `themes.css` and
   `prepaint.blade.php`, and writes `docs/design-system/palette-report.md`.
3. Read the report. A failed role or gradient stops here: choose another step in
   `roles` or another colour. Never edit generated files.
4. Run `npm run palette:check`, `npm run build`, and then the colour tests and the
   browser tests.
5. Commit the configuration, the generated files and the report together. The
   commit message names the old and new palette.

No component file changes as part of a palette change. If one seems necessary,
a role is missing: add it (§11), don't recolour the component.

## 15. Component compliance (for every new or changed component)

- Uses only token utilities (§11) and passes checks 1, 3 and 8.
- Every text/background pairing it introduces is already a declared pair, or is
  added to the test in the same change.
- Hover, active, focus, selected and disabled states use the role's state
  tokens or opacity modifiers, consistently with its family.
- Status is never shown by colour alone.
- It works unchanged in every theme. Check this in the component gallery `/ui`
  in light and dark.

## 16. Versioning and change management

- The standard uses semantic versions:
    - MAJOR: a token renamed or removed, or a layer rule changed
    - MINOR: a role, check or component token added
    - PATCH: wording, or a tightened threshold that every project already meets
- Every change adds a line to §19 with the version, date and summary.
- **Downstream projects** (e.g. `cyber_security`) hold an independent copy of
  this document, the generator, the CSS layers and the tests. They have no
  runtime or build dependency on the template: no submodules, symlinks or
  absolute template paths. Their copy's header records
  `Adopted: <version> on <date>` and lists any local adaptations.
- **Update protocol.** When the standard changes:
    1. Update the template copy and bump the version.
    2. Run the template's validation.
    3. List the affected downstream projects.
    4. Write a compatibility report for each.
    5. Apply the approved changes to each project by hand, keeping its palette,
       `roles` and local tokens.
    6. Validate each project independently.

    Nothing is synchronised automatically.

## 17. Implementation status (template)

| Part                                                    | Status                                                             |
| ------------------------------------------------------- | ------------------------------------------------------------------ |
| This standard, CLAUDE.md reference, Cursor rule         | Implemented (G1)                                                   |
| L1 palette, generator, palette.json, pairs.json, report | Implemented, Tested (G2)                                           |
| L2 roles (light, dark), L3 component tokens             | Implemented, Tested (G2)                                           |
| Generated pre-paint background                          | Implemented, Tested (G3)                                           |
| Gradients                                               | Implemented; none declared in the template                         |
| Migration of raw colours and `destructive-foreground`   | Implemented, Tested (G2)                                           |
| Checks 1–9                                              | Implemented, Tested (G2, G3)                                       |
| Browser check that components paint their tokens        | Implemented, Tested (`tests/browser/colors.spec.ts`, G3)           |
| Acceptance tests A–F                                    | Verified (G3): `docs/evidence/color-system/template-acceptance.md` |
| Check 10 pixel baselines                                | Planned (not in ENG-DESIGN-COLOR-001's scope)                      |
| Further themes beyond light and dark                    | Proposed                                                           |

## 18. Migration notes for 1.0.0 (done in the template)

- `text-destructive-foreground` (red text on the page) became
  `text-destructive-text`, and `destructive-foreground` is now the text on a
  destructive solid. Plain `text-destructive` and `text-success` used as text
  became `text-destructive-text` and `text-success-text`.
- `text-white` on the destructive button and badge became
  `text-destructive-foreground`.
- `bg-black/80` in dialog and sheet became `bg-overlay`.
- `decoration-neutral-300 dark:decoration-neutral-500` on links became
  `decoration-link-underline` (L3).
- The split auth layout's `bg-zinc-900 text-white` panel became
  `bg-brand-surface text-brand-surface-foreground`.
- The default button uses the L3 `button-primary` tokens, with
  `primary-hover` and `primary-active` instead of `bg-primary/90`.
- Table heads and selected rows use the L3 `table-head` and
  `table-row-selected` tokens.
- The sidebar's `hsl(var(--sidebar-border))` shadows (invalid with OKLCH
  values) became `var(--sidebar-border)`.
- The QR code's `bg-white` became `bg-[white]`, an approved exception (§13).
- Tailwind's default palette is switched off (`--color-*: initial`), so a raw
  palette utility produces no CSS at all, besides failing check 1.

## 19. Change log

| Version | Date       | Change                                |
| ------- | ---------- | ------------------------------------- |
| 1.0.0   | 2026-11-15 | First version (ENG-DESIGN-COLOR-001). |
