# ADR-0024: Design system foundation: languages, direction, tokens, browser checks

- Status: Accepted
- Date: 2026-11-12

## Context

The template serves Arabic and English users. The starter kit it grew from
was English-only and left-to-right, loaded its font from a third-party
server, used raw palette colours, and had no browser-level checks. Its
theme script was also blocked by our own Content-Security-Policy (no nonce).

## Decision

**Languages and direction**

- `platform.locales` lists the languages and their direction (`ar` rtl,
  `en` ltr). `SetLocale` applies the choice in the unencrypted `locale`
  cookie when it is listed; otherwise `app.locale`. The root view sets
  `<html lang dir>`; Radix gets the direction through `DirectionProvider`.
- Strings live in `lang/<locale>.json`, keyed by the English text (the
  Laravel convention), shared with the page once per load
  (`Inertia::once`). The frontend uses `useTranslations().t(key, {name})`;
  data that is shown elsewhere (menu items, layout titles) holds English keys
  and is translated where it is rendered. Laravel's own messages are in
  `lang/ar/{auth,passwords,pagination,validation}.php`.
- Layout uses logical properties only (`ms`/`me`, `ps`/`pe`, `start`/`end`,
  `text-start`); the sidebar takes the reading side; arrows mirror.
- Changing language reloads the page so the server renders the new
  direction.

**Tokens**

- Semantic colour tokens in `resources/css/app.css`, light and dark, plus
  `success`, `warning`, `info`. Components use tokens only.
- `DesignTokensTest` converts every token pair from OKLCH to sRGB and checks
  WCAG AA: 4.5:1 for text, 3:1 for field borders and focus rings, in both
  themes; it also rejects raw palette colours outside `components/ui`.
- Font: IBM Plex Sans Arabic (SIL OFL 1.1), Arabic and Latin, self-hosted
  through the Vite fonts plugin from `node_modules`; bunny.net is gone from
  the CSP. Reduced motion is honoured globally.

**Checks**

- `TranslationsTest`: every key has an Arabic translation with the same
  placeholders, no unused keys, the framework files are complete, and no
  English text is hard-coded in `.tsx` (text, attributes, `??` defaults and
  ternaries).
- Playwright (`tests/browser`, browsers in `.tools/ms-playwright`): 7 pages
  x Arabic/English x light/dark x phone/desktop; `<html dir>`, axe WCAG 2.2
  A/AA with zero violations, no horizontal overflow, sidebar side.
  Runs against `php artisan serve` with its own `database/browser.sqlite`,
  signing in with password and a TOTP code like a person.
- Bundle budget (`npm run budget`): first-visit JS and CSS per page,
  gzipped: 210 kB and 20 kB. Measured 177-201 kB JS; most is react-dom,
  Inertia, tailwind-merge and the Radix menus shared by every layout.
  Splitting layouts into lazy chunks would save the sign-in pages about
  40 kB at the cost of a round trip on every application page; not done.
- CI job `browser` runs build, budget and the browser tests, and keeps the
  screenshots as an artifact.

## Consequences

A new page or component cannot ship English-only text, untested contrast or
a physical-direction layout without a test failing. Adding a language is a
`platform.locales` entry plus `lang/<locale>.json` and `lang/<locale>/*.php`.

## Security implications

The theme and language cookies are unencrypted and therefore validated
against fixed lists before use; the theme reaches the inline script through
`@json`. The inline script carries the request's CSP nonce. No third-party
font requests.

## Performance implications

Translations add about 10 kB (uncompressed) to the first page load only.
The font preloads one weight (400) per subset.

## Reversal or migration strategy

Remove a locale from `platform.locales`; strings stay keyed by English, so
English always works.
