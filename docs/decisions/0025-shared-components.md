# ADR-0025: Shared interface components and the component gallery

- Status: Accepted
- Date: 2026-11-12

## Context

Phase 7 adds the 28 interface improvements (docs/design-system/
improvements.md). Each is used by many pages, so each must behave the same
everywhere and be proved once, in every state, rather than page by page.

## Decision

- Shared components live in `resources/js/components/` (application level)
  on top of `components/ui/` (shadcn/Radix primitives). A page never
  re-implements a behaviour a shared component offers.
- Every shared component:
    - takes translated text from its caller or translates its own fixed text
      (`useTranslations`); no English literals (TranslationsTest);
    - uses tokens only (DesignTokensTest) and logical CSS properties;
    - is typed (no `any`), with its props documented in the file;
    - is keyboard operable with visible focus, and announces state changes
      (role/aria-live) where it has any;
    - works from 390 px up.
- **Component gallery** at `/ui` (`pages/dev/gallery.tsx`): each shared
  component in each of its states. The route is registered only in
  `platform.ui.gallery_environments` (`local`, `browser`); production,
  staging and testing have no such route (ComponentGalleryTest).
- `tests/browser/pages.spec.ts` checks the gallery and the real pages in
  Arabic/English, light/dark, at 390, 820 and 1366 px, with axe WCAG 2.2
  A/AA and no overflow. Behaviour that axe cannot see (focus return, Esc,
  live announcements) gets its own browser test with the component.

## Consequences

The gallery's page code ships in the build like any page (a separate chunk
loaded only when visited); its route does not exist in production.

## Security implications

None beyond the gallery route being absent outside development; it renders
static examples and reads no data.

## Performance implications

Every component is checked against the per-page bundle budget (ADR-0024).

## Reversal or migration strategy

Remove `local` from `gallery_environments` to hide it in development too.
