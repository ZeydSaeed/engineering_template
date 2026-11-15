# Color Design System

- Standard: ENG-DESIGN-COLOR-001
- Version: 1.0.0 (2026-11-15)
- Status: Accepted. The architecture is **Planned** until the implementation
  status table (§17) says otherwise.
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

| File                                                                 | Role                                                                    | Edited by          |
| -------------------------------------------------------------------- | ----------------------------------------------------------------------- | ------------------ |
| `resources/design-system/palette.json`                               | Owner input: colours, names, role assignments, gradients                | owner / agent      |
| `resources/design-system/exceptions.json`                            | Approved exceptions to §13 (path, pattern, reason, approver, date)      | with approval only |
| `scripts/design-system/palette.mjs`                                  | Analyser and generator (`npm run palette`, `npm run palette:check`)     | engineers          |
| `resources/css/design-system/palette.css`                            | L1, generated                                                           | generator only     |
| `resources/css/design-system/themes.css`                             | L2 per theme (L4), generated                                            | generator only     |
| `resources/css/design-system/components.css`                         | L3, theme-agnostic                                                      | engineers          |
| `resources/css/app.css`                                              | Imports the three files; the `@theme` block exposing tokens to Tailwind | engineers          |
| `docs/design-system/palette-report.md`                               | Generated analysis: classification, scales, contrast, warnings          | generator only     |
| `tests/Feature/Platform/DesignTokensTest.php`, `ColorSystemTest.php` | Validation (§12)                                                        | engineers          |

Generated files start with a `GENERATED by scripts/design-system/palette.mjs —
do not edit` header. The pre-loading background colour in
`resources/views/app.blade.php` must equal `--background` of each theme, and a
test enforces this (ADR-0024).

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
  `"brand-surface": "primary-900"`. Anything not given uses the defaults in §6.
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
    - hue family: red, orange, yellow, green, teal, blue, purple, pink (30° bins)
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

| Step | 50   | 100   | 200  | 300  | 400  | 500  | 600  | 700  | 800  | 900  | 950  |
| ---- | ---- | ----- | ---- | ---- | ---- | ---- | ---- | ---- | ---- | ---- | ---- |
| L    | 0.97 | 0.935 | 0.87 | 0.79 | 0.70 | 0.61 | 0.53 | 0.45 | 0.37 | 0.29 | 0.21 |

It also produces `--palette-<family>-base`, the exact supplied colour.

### 5.2 Generation rules

- **Hue is preserved.** Every step keeps the supplied hue H. A family derived
  for a missing colour uses a default hue (§5.3).
- **Anchor.** The step whose target L is nearest to the supplied L takes the
  exact supplied colour. Its lightness differs from the target by less than half
  a step, so lightness stays monotonic. The report names the anchor step.
- **Chroma curve.** Steps other than the anchor use
  `C(step) = C_base × k(L)`. Here k rises from 0.35 at L 0.97 to 1.0 at the
  anchor and falls to 0.6 at L 0.21. This keeps tints and shades from turning
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
to `destructive-text`, the 14 usages are migrated in G4 (§18), and
`destructive-foreground` becomes the text on a destructive button.

### 6.2 Default mapping

Defaults per theme (L4). `roles` in `palette.json` may override any row.

| Role                    | Light                                                      | Dark                                          |
| ----------------------- | ---------------------------------------------------------- | --------------------------------------------- |
| background              | neutral-50                                                 | neutral-950                                   |
| foreground              | neutral-950                                                | neutral-50                                    |
| card                    | white (`oklch(1 0 0)` via neutral-0)¹                      | neutral-900                                   |
| popover                 | white¹                                                     | neutral-800                                   |
| muted / secondary       | neutral-100 / secondary-100                                | neutral-800 / secondary-900                   |
| muted-foreground        | neutral-700                                                | neutral-300                                   |
| accent                  | primary-100                                                | primary-900                                   |
| primary                 | primary at anchor if it carries text, else primary-600     | primary-300 or -400 (first to pass)           |
| primary-hover / -active | one / two steps darker                                     | one / two steps lighter                       |
| primary-subtle          | primary-50                                                 | primary-950 mixed 60% with neutral-900        |
| brand-surface           | primary-900                                                | primary-950                                   |
| highlight               | accent at anchor                                           | accent-400 (or the anchor if it still passes) |
| border / border-strong  | neutral-200 / neutral-600                                  | neutral-800 / neutral-500                     |
| ring                    | primary-600                                                | primary-300                                   |
| status solid            | <family>-600                                               | <family>-400                                  |
| status text             | <family>-700                                               | <family>-300                                  |
| overlay                 | neutral-950 at 80%                                         | neutral-950 at 80%                            |
| chart-1…5               | primary-600, secondary-500, accent-500, extra/derived hues | the 300–400 steps of the same families        |

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
- Every role is listed separately in each theme block. A theme block that copies
  every value from light fails the theme-consistency check (§12, check 6) unless
  `roles` says so explicitly for that role.

### 6.4 Further themes

A theme is an L2 block under `[data-theme='<name>']` (and `.dark` keeps its
class for Tailwind's `dark:` variant). It must define every L2 token. Themes are
listed in `palette.json` under `themes` (default `["light", "dark"]`), and every
check runs for every listed theme.

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
- **Declared pairs.** The tests check an explicit list of pairs, per theme:
    - every `X` / `X-foreground`
    - every `X-text` on `background` and on `card`
    - `muted-foreground` on `background`, `card` and `muted`
    - `primary` used as link text on `background`
    - `border-strong`, `input` and `ring` on every surface they touch
    - every gradient stop
    - every L3 text pair

    A new pairing in a component means a new declared pair in the test, in the
    same change.

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

| #   | Check                 | How                                                                                                                                                                                                  |
| --- | --------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Hardcoded UI colours  | `ColorSystemTest`: scans `resources/js/**/*.tsx`, `resources/views/**/*.blade.php` and non-generated CSS for §11's forbidden forms, including `components/ui`; `exceptions.json` is the only way out |
| 2   | Duplicate tokens      | No token is declared twice in one layer or theme block; no two `@theme` entries expose the same name                                                                                                 |
| 3   | Missing tokens        | Every `var(--x)` and every token utility used in components resolves to a declared token; every theme defines every L2 token                                                                         |
| 4   | Unused tokens         | Every exposed L2/L3 token is used by at least one component, CSS rule or another token (allow-list for roles kept for downstream projects, e.g. `brand-surface`)                                     |
| 5   | Layer references      | L1 has literals only; L2 references L1 only; L3 references L2 only (§2)                                                                                                                              |
| 6   | Theme consistency     | Same key set in every theme; a dark block identical to light for a role needs an explicit `roles` entry                                                                                              |
| 7   | Contrast              | `DesignTokensTest`: every declared pair (§10), every theme                                                                                                                                           |
| 8   | Component compliance  | Check 1 plus: every shared component in `components/ui` and `components/` uses only token utilities; reported per file                                                                               |
| 9   | Palette configuration | `npm run palette:check`: JSON schema, valid HEX, roles refer to existing steps, generated files are up to date (regenerate and compare byte for byte)                                                |
| 10  | Visual regression     | Playwright `tests/browser` (axe and screenshots on every page × theme × direction × width). Pixel-baseline comparison is **Planned**: the screenshots are kept as CI artifacts but not compared yet  |

Checks 1–9 run in `composer test` / CI. Check 9 also runs in CI's `browser` job
before the build.

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
2. Run `npm run palette`. It regenerates `palette.css` and `themes.css`, and
   writes `docs/design-system/palette-report.md`.
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

| Part                                                            | Status                                  |
| --------------------------------------------------------------- | --------------------------------------- |
| Semantic tokens, light/dark, contrast test (ADR-0024)           | Implemented, Tested                     |
| This standard, CLAUDE.md reference, Cursor rule                 | Implemented (G1)                        |
| L1 palette, generator, palette.json, report                     | Planned (G2)                            |
| L2 extended roles, L3 component tokens, gradients               | Planned (G2)                            |
| Migration of the 13 raw colours and of `destructive-foreground` | Planned (G2)                            |
| Checks 1–9                                                      | Planned (G2/G3)                         |
| Check 10 pixel baselines                                        | Planned (not in this directive's scope) |

## 18. Migration notes for 1.0.0

- `destructive-foreground` (red text on the page) becomes `destructive-text`,
  and `destructive-foreground` becomes the text on a destructive solid (white or
  as chosen). There are 14 usages in the template.
- `text-white` on destructive button and badge becomes `text-destructive-foreground`.
- `bg-black/80` in dialog and sheet becomes `bg-overlay`.
- `decoration-neutral-300/500` on links becomes `decoration-border-strong` (or
  a `link-decoration` L3 token).
- The split auth layout's `bg-zinc-900 text-white` panel becomes
  `bg-brand-surface text-brand-surface-foreground`.
- The QR code's `bg-white` becomes an approved exception (§13).

## 19. Change log

| Version | Date       | Change                                |
| ------- | ---------- | ------------------------------------- |
| 1.0.0   | 2026-11-15 | First version (ENG-DESIGN-COLOR-001). |
