# Manual checks (Phase 7)

What automated tests cannot judge. Each check names who ran it, when, on
what, and the result. Status NOT TESTED until someone runs it. Phase 10
automated what can be (keyboard, reflow, forced colors); the rest needs a
person with a screen reader, a real device or speech input. Automated
browser tests (axe, focus, keyboard, RTL) cover a lot; these checks are
about how it actually sounds and feels.

## Screen reader (NVDA with Firefox or Chrome on Windows; VoiceOver on a phone)

| Check                        | Steps                                                   | Expected                                                                                                     | Status     |
| ---------------------------- | ------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ | ---------- |
| Sign in (Arabic and English) | /login; Tab through; submit wrong data                  | Each field announced with its label; errors announced after submit                                           | NOT TESTED |
| Live validation              | Settings > Profile: type a wrong email, leave the field | The error is read once, linked to the field                                                                  | NOT TESTED |
| Empty states                 | /ui, empty-state section                                | Heading and description in order; the error kind as an alert                                                 | NOT TESTED |
| Language switcher            | Open it, pick the other language                        | Each language read in its own language                                                                       | NOT TESTED |
| User table                   | /admin/users: sort, select rows, open details           | Sort state announced on the column header; selection count announced; the panel's heading read when it opens | NOT TESTED |
| Bulk result                  | Grant a role to two people and yourself                 | The result (2 updated, 1 not, why) read without moving focus                                                 | NOT TESTED |
| Upload                       | Settings > Documents: choose a file                     | Progress named after the file; "uploaded" or the refusal announced                                           | NOT TESTED |
| Invitation steps             | Admin > Users > Invite: go through the steps            | Each step's heading read when it appears; "Step n of 3"                                                      | NOT TESTED |
| Search                       | Ctrl+K, type, arrows                                    | The active result read as it moves; the result count announced                                               | NOT TESTED |
| Notifications                | Header link; Notifications page                         | "Notifications, n unread"; "New" read for unread items                                                       | NOT TESTED |
| Chart                        | Admin > Reports: Tab into a chart                       | Each column read with its day and value; the table view read as a table                                      | NOT TESTED |

## Keyboard only

| Check                                  | Steps                                                    | Expected                                                                   | Status                                                                                                                                                                                                                             |
| -------------------------------------- | -------------------------------------------------------- | -------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Whole app without a mouse              | Tab / Shift+Tab / Enter / Space / Esc through every page | Visible focus everywhere, logical order (right to left in Arabic), no trap | Automated (Phase 10): tests/browser/assistive.spec.ts tabs through seven main pages in Arabic and English in three engines: every stop shows focus, focus never trapped. A person still judges the order and the whole application |
| Single-key shortcuts with speech input | Dragon or Windows Voice Access, shortcuts on, then off   | With them off, dictated words never trigger an action                      | NOT TESTED                                                                                                                                                                                                                         |

## Visual and devices

| Check                       | Steps                                            | Expected                                                          | Status                                                                                                                                                                                                                                                         |
| --------------------------- | ------------------------------------------------ | ----------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Arabic text rendering       | Screenshots in docs/evidence/phase-7/screenshots | Letters joined, diacritics not clipped, comfortable line height   | Reviewed by Claude on dashboard-ar-desktop and login-ar-phone (2026-11-12): correct                                                                                                                                                                            |
| Safari and Firefox          | Open the main pages                              | Same as Chromium                                                  | Automated since Phase 8: every browser test runs in Firefox and WebKit too (205/205 each, local and CI). Real Safari on macOS and iOS: NOT TESTED (WebKit is its engine, not Safari itself)                                                                    |
| Real phone and tablet       | Android and iPhone; an iPad or Android tablet    | Touch targets easy to hit; drop zone falls back to "Choose files" | NOT TESTED                                                                                                                                                                                                                                                     |
| 200% zoom and 320 px reflow | Browser zoom 200%; window 320 px wide            | Nothing cut off or overlapping (WCAG 1.4.4, 1.4.10)               | Automated (Phase 10): tests/browser/reflow.spec.ts, 15 pages at 320 px in both languages (found and fixed the pagination cut off in Arabic); 640 px (200 % of 1280) checked once in the Phase 10 review. Real browser zoom by a person: NOT TESTED             |
| Windows high contrast       | Contrast themes on                               | Focus, buttons and chart columns still visible                    | Partly automated (Phase 10): forced colors emulated in Chromium (assistive.spec.ts) found focus invisible on nearly every control (rings drawn as shadows); fixed with an outline in forced colors. Chart columns and real Windows contrast themes: NOT TESTED |
