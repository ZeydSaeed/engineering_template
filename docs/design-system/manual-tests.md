# Manual checks (Phase 7)

What automated tests cannot judge. Each check names who ran it, when, on
what, and the result. Status NOT TESTED until someone runs it.

## Screen reader

| Check             | Steps                                                                   | Expected                                                                       | Status                                             |
| ----------------- | ----------------------------------------------------------------------- | ------------------------------------------------------------------------------ | -------------------------------------------------- |
| Sign in (Arabic)  | NVDA + Firefox or Chrome, Windows; open /login with Arabic; Tab through | Each field announced with its Arabic label; errors announced after submit      | NOT TESTED (needs the owner or a tester with NVDA) |
| Sign in (English) | Same in English                                                         | Same                                                                           | NOT TESTED                                         |
| Empty states      | /ui, empty-state section                                                | Heading and description read in order; the error kind is announced as an alert | NOT TESTED                                         |
| Language switcher | Open it, pick the other language                                        | Each language announced in its own language (lang attribute)                   | NOT TESTED                                         |

## Keyboard only

| Check                     | Steps                                                                      | Expected                                                                   | Status                                                      |
| ------------------------- | -------------------------------------------------------------------------- | -------------------------------------------------------------------------- | ----------------------------------------------------------- |
| Whole app without a mouse | Tab / Shift+Tab / Enter / Space / Esc through sign-in, dashboard, settings | Visible focus everywhere, logical order (right to left in Arabic), no trap | NOT TESTED (batch 1 adds automated focus tests for dialogs) |

## Visual

| Check                 | Steps                                            | Expected                                                        | Status                                                                              |
| --------------------- | ------------------------------------------------ | --------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| Arabic text rendering | Screenshots in docs/evidence/phase-7/screenshots | Letters joined, diacritics not clipped, line height comfortable | Reviewed by Claude on dashboard-ar-desktop and login-ar-phone (2026-11-12): correct |
| Safari and Firefox    | Open the main pages                              | Same as Chromium                                                | NOT TESTED (browser tests use Chromium only)                                        |
