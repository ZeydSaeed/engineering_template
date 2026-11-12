# ADR-0026: Server-side tables, per-person preferences and saved views

- Status: Accepted
- Date: 2026-11-12

## Context

Administrative screens list thousands of records. Loading them all into the
browser is slow and leaks data the person does not need; letting request
input reach `ORDER BY` or `LIKE` unchecked is an injection and performance
risk. People also expect the screen to remember how they like it.

## Decision

**Tables** (`app/Platform/Tables`)

- A `TableDefinition` per table declares its columns (sortable, hideable),
  filters with their permitted values, page sizes (10, 25, 50, 100) and the
  search. `TableState::from()` keeps only declared values; anything else is
  dropped, never passed to a query. A unique last sort key keeps pages
  stable.
- Search: `whereContains()` compares lower-cased text with `LIKE ... ESCAPE
  '!'`, so `%` and `_` are taken literally on PostgreSQL and SQLite alike.
  Column names come from the definition only.
- The state lives in the URL (bookmarkable); the page reloads only the
  table's props (Inertia partial reloads).
- Sortable columns are indexed (`users.name`, `users.created_at`; `email`
  is unique). Searching inside text (`%x%`) cannot use a B-tree index; at
  10,000 users it costs 35 ms (measured). A larger table adds a `pg_trgm`
  GIN index.

**Preferences and views**

- `user_preferences` (user, key, jsonb value): the column order and hidden
  columns per table, reconciled with the definition on every read and
  write.
- `saved_views`: named states (search, filters, sort, size, columns), at
  most 20 per person and table, the same name replacing the view; a
  person sees and deletes only their own (another's is 404).
- `TableRegistry` names each table and the ability needed to see it; the
  shared endpoints (`PUT tables/{table}/columns`, `POST/DELETE
  tables/{table}/views`) refuse tables the person may not see.

**Bulk actions**

- One request carries at most 100 ids. Each record goes through the same
  Action as a single change (here `AssignRole` / `RevokeRole`: lock,
  re-check of permission, last administrator, no self-change, audit), and
  the response lists who changed and who did not, with the reason.

**Details and history**

- The details panel loads with a partial reload (`?user=ID`), so the list
  never carries details. The history comes from the audit trail
  (`ActivityTimeline`): events about the record and, for a person, events
  they caused; only allow-listed metadata (`role`) is shown, never IP
  addresses or user agents; only for people with `audit.view`.

**Interface**

- `components/data-table/*`: DataTable (aria-sort, selection with
  select-all on the page, scroll region reachable by keyboard), toolbar
  (debounced search, filters), pagination, column settings (reorder by
  buttons, not dragging), saved views, bulk bar and result panel; the
  drawer gives focus back to the control that opened it even when opened
  from code.
- `auth.permissions` is shared so the interface offers only what the
  person may do; the server checks every request regardless.

## Consequences

A new list screen writes a definition, a controller and column cells; the
behaviour, accessibility and tests come with the shared components.

## Security implications

No request value reaches SQL except as a bound parameter; sort and filter
keys are allow-listed; preferences and views are per person; bulk actions
keep every per-record rule.

## Performance implications

Measured with 10,000 users on PostgreSQL 18 (tests/Feature/Performance/
LargeTableTest.php): 18.6-43.4 ms per request, 5 queries, at most 3.7 MB
memory growth. The test fails on more than 12 queries, 1.5 s or 32 MB.

## Reversal or migration strategy

Definitions are per table; a table can return to a hand-written query
without affecting others. Dropping the two tables removes preferences and
views only.
