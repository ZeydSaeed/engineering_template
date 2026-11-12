# ADR-0029: Notifications, dashboard widgets, reports, tablet layout

- Status: Accepted
- Date: 2026-11-13

## Context

The last Phase 7 improvements: #8 (notification centre), #1 (configurable
dashboard), #21 (interactive reports and charts) and #11 (tablet layouts).

## Decision

**Notifications**

- Laravel's database channel, with times kept to the microsecond
  (`StoredNotification`, `timestampsTz(6)`) so notifications sent in the
  same second keep their order. Indexes on (notifiable, read_at) for the
  count and (notifiable, created_at) for the list.
- The server stores facts (`kind`, `role`, `by`); the interface writes
  the sentence in the reader's language. A role change notifies the
  person only when the role really changed (granting a held role is a
  no-op).
- The header shows the unread count, in the link's name as well as the
  badge ("Notifications, 2 unread"); the page marks one or all read, and
  marks unread in words ("New"), not by colour alone. Another person's
  notification is 404.

**Dashboard widgets**

- Widgets: notifications, the person's activity, their documents, and
  people (roles.manage only). A widget the person may not see does not
  exist for them; a hidden widget's data is never computed. Order and
  hidden widgets are kept on the server (`dashboard:widgets`), reconciled
  with what the person may see. Customising reuses the column settings
  dialog (show/hide, reorder by buttons).

**Reports and charts**

- `/admin/reports` for audit.view: sign-ins per day over 7, 30 or 90 days
  (UTC days, zero for days without), and people by role.
- Charts are hand-made SVG (no chart library, for the bundle budget),
  following the data-visualisation method: one series in one colour (the
  primary token, contrast checked by DesignTokensTest), columns at most
  24 px with a 4 px rounded end on a single baseline, hairline grid, no
  legend for one series, selective axis labels. Every column is
  keyboard-reachable and named with its value; hover or focus shows it; a
  table view carries every number; the time axis starts at the reading
  side (the right in Arabic).

**Tablet**

- With no sidebar choice yet, the sidebar starts as icons below 1280 px,
  so the content keeps its width; widgets use one column on phones, two
  on tablets and three on wide screens.

**Budgets**

- Signed-in pages run one more indexed query (the unread count); the
  dashboard seven in all (QueryBudgetTest). The shortcuts list moved to a
  chunk loaded on first use, bringing the base every page loads from
  184.7 to 181.9 kB (budget 185).

## Consequences

A module adds a notification kind (server fact + interface sentence), a
widget (permission + data + card) or a report page with BarChart.

## Security implications

Notifications, widgets and reports are scoped to the person and their
permissions; report periods are a fixed list.

## Performance implications

Constant query counts on indexes; widget data only for visible widgets.

## Reversal or migration strategy

Widgets and notification kinds are additive; removing one leaves the rest
working.
