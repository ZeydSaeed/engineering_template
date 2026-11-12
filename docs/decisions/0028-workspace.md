# ADR-0028: Workspace: preferences, global search, shortcuts, help

- Status: Accepted
- Date: 2026-11-13

## Context

Improvements #28 (preferences kept on the server), #5 (global search),
#17 (keyboard shortcuts) and #27 (contextual help) shape how a person
works across every page.

## Decision

**Preferences** (`WorkspacePreferences`, `PreferencesController`)

- A signed-in person's language, theme, density (comfortable/compact)
  and single-key shortcuts (on/off) live in `user_preferences`
  (`workspace`), checked against fixed lists. The saved language and theme
  win over the cookies; the cookies are written too, so the sign-in page
  after a sign-out keeps them.
- Read once per session and kept in it (no query per page); the first
  request of a session pays one indexed read. Choices made on another
  device apply from the next sign-in.
- The interface saves each change with its own request (fetch, CSRF
  header), not an Inertia visit, because a visit cancels the one before
  it and quick changes were lost. Each change applies across the interface
  at once and is undone if the server refuses it.
- Saving merges under a row lock (insert-or-ignore, then SELECT ... FOR
  UPDATE): two saves at the same moment both stay (RaceConditionsTest,
  real parallel processes, PostgreSQL).
- Compact density narrows rows in tables and lists; targets stay at least
  24 px (WCAG 2.5.8).

**Global search** (`SearchController`, `command-palette.tsx`)

- Ctrl+K / ⌘K or the header button. ARIA combobox with a listbox,
  aria-activedescendant, arrows and Enter, a polite result count; focus
  returns to the opener. Pages come from the navigation the person
  already has; records from `GET /search`, only what the person may see
  (users for roles.manage), at least two characters, five results per
  kind, `%` literal, 60 requests a minute. Loaded when first opened.

**Shortcuts** (`keyboard-shortcuts.tsx`)

- Ctrl+K / ⌘K always; single keys `?` (list), `/` (the page's search
  field), `g` then `d` / `u` / `s`. Ignored while typing in a field.
  Single-key shortcuts can be turned off (WCAG 2.1.4), and the setting
  applies at once.

**Contextual help** (`help-tip.tsx`)

- A disclosure button ("Help: topic", aria-expanded, aria-controls) that
  shows short help in place; not a hover tooltip, so it works on touch and
  with the keyboard and stays until closed.

**Dialogs**

- The close button of dialogs and side panels is 28 px (it was 16, below
  WCAG 2.5.8 next to other controls) and sits at the reading end.

## Consequences

New pages get search, shortcuts and density by using the shared table and
toolbar; a new record type adds a section to SearchController with its own
permission check.

## Security implications

Search returns only what the person may see and is rate-limited;
preferences accept only listed values; the language and theme cookies are
validated before use (ADR-0024).

## Performance implications

One preference read per session; search runs a limited, indexed-prefix
query only from two characters, after a 250 ms pause in typing.

## Reversal or migration strategy

Removing a preference key leaves the default; the cookies keep working for
guests.
