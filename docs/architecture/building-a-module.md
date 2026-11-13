# Building a module

A step-by-step guide for adding a business area, using `Requests`
(ADR-0033) as the worked example. Every step names the file in that module
to copy from. Read `README.md` in this folder first for the layers and the
dependency rules; deptrac and `tests/Architecture` enforce them.

## 0. Decide what the module owns

- One business area, named as a plural noun (`Requests`, `Invoices`).
- Its states and the moves between them (a state machine), who may do
  what, what must be audited, and who must be told.
- What other code may read from it. That becomes `Contracts`; nothing
  else of the module is visible outside it.

## 1. The provider: everything the module adds, in one place

`app/Modules/Requests/RequestsServiceProvider.php`, registered in
`bootstrap/providers.php`. Removing the module means removing that line
and the folder.

- `register()`: outbox topics and their handlers (add them to
  `platform.outbox.handlers`; never replace the list), bindings of the
  module's `Contracts`, and its metrics source
  (`$this->app->tag([...], MetricsSource::TAG)`).
- `boot()`: `loadMigrationsFrom`, `loadRoutesFrom`, the morph alias
  (`Relation::morphMap`), `Gate::policy`, and any table it registers with
  `TableRegistry`.

## 2. Domain: the rules without the database

`Domain/RequestStatus.php`, `Domain/Decision.php`.

- An enum per state machine with `canBecome()`, so a move that is not
  allowed cannot be written by mistake. A unit test lists every allowed
  move (`tests/Unit/Requests/RequestStatusTest.php`).
- No Eloquent and no facades in `Domain`.

## 3. Data: the table and its constraints

`Database/migrations/..._create_approval_requests_table.php`.

- A bigint id inside and a UUIDv7 `uuid` for URLs (`HasUuids` with
  `uniqueIds()`; the route key is the UUID). The id never reaches a page.
- `timestampTz`; `numeric` for money; foreign keys `restrictOnDelete`
  unless deleting the parent must delete the rows; an index for each read
  path and each foreign key (`PostgresConventionsTest` checks the last
  two).
- Repeat the state machine as PostgreSQL `CHECK` constraints, and name
  NULL in each rule (a CHECK passes when its expression is NULL). Test
  them by writing bad rows past the actions
  (`tests/Feature/Requests/RequestConstraintsTest.php`).
- A `lock_version` column if people edit the same row from forms.
- A factory with states for each status; its users have a second factor
  (`User::factory()->withTwoFactor()`), or the pages redirect to the 2FA
  setup.

## 4. Application: one action per change

`Application/Actions/*.php`. Each action:

- runs in one transaction and locks the row it changes (`lockForUpdate`),
  then reads the state again under the lock;
- checks the rule (the status move, who may do it; re-read permissions
  inside the transaction for anything sensitive) and throws
  `DomainRuleViolation` with the field it concerns;
- is harmless when repeated (a second click returns without change);
- records an audit event (`AuditLogger::record`, action names
  `<thing>.<verb>`, each with a label in the activity timeline and
  `lang/ar.json`) and, when someone must be told, an outbox message
  (`Outbox::record`) in the same transaction.

Outbox handlers are jobs (`Application/Jobs`, extending `OutboxJob`) that
send notifications (`Application/Notifications`). Reads other code needs
go in `Application/Queries`, behind an interface in `Contracts`.

Test every rule (`tests/Feature/Requests/RequestLifecycleTest.php`) and a
real race of two people acting at once (`tests/Concurrency`, PostgreSQL;
four processes at most, the test role's connection limit is 10).

## 5. Access

- Add permissions to `app/Access/Permission.php` and grants to
  `app/Access/Role.php` (a new role if the work belongs to new people).
  Write every grant in `tests/Feature/Access/PermissionMatrixTest.php`.
- A policy in `Http` (`RequestPolicy.php`). For a change whose refusal
  depends on the current state (already decided, no longer a draft),
  authorise viewing only and let the action answer precisely, rather than
  a bare 403.

## 6. HTTP and pages

- `Http/routes.php` with `web`, `auth`, `verified`; `whereUuid` on every
  route parameter (a malformed one is a 404, not a database error);
  throttles on writes; Precognition on forms.
- Controllers do not extend `App\Http\Controllers\Controller` (a module's
  `Http` may not use `App\Http`); use `Gate::authorize`.
- Form requests for input; a presenter that sends plain values and what
  the viewer may do (`can`), never models.
- Lists: a `Table` class (server-side sorting, filtering, paging,
  ADR-0026), registered in the provider.
- Pages in `resources/js/modules/<name>/pages`, rendered as
  `modules/<name>/pages/<page>`. Use the shared components
  (`FormField`, `ConfirmAction`, `DataTable`, `ActivityTimeline`,
  `FileUpload`); a form's button is disabled only while sending, never
  while a field is being checked.
- Every new sentence in `lang/ar.json` (`TranslationsTest` finds missing
  ones).

## 7. Integration with the rest of the application

The core application knows the module only through its `Contracts` (the
dashboard widget reads `RequestsOverview`) and its generated route
helpers (navigation, notification links). Never import a module's models
or actions from `app/Http` or another module.

## 8. Observability

- A `MetricsSource` (`Application/Metrics/RequestsMetrics.php`): metrics
  named `module_<name>_*`, each an indexed count, always exported (0 when
  empty, so an alert on it can fire). A source that fails marks itself
  down (`platform_metrics_source_up`) without breaking `/metrics`.
- Alerts in their own group in `deploy/monitoring/alerts.yml` and a
  runbook in `docs/runbooks/<name>.md` (meaning, check, act, verify);
  `AlertRulesTest` checks both and that every metric used exists.

## 9. Tests and measurements

- Pages through HTTP (`tests/Feature/Requests/RequestPagesTest.php`).
- The browser, in Arabic and English, with axe
  (`tests/browser/requests.spec.ts`), and the module's pages in
  `pages.spec.ts`.
- A large table on PostgreSQL with query, time and memory budgets and the
  plans of the main reads (`tests/Feature/Performance/RequestsLargeTableTest.php`).
- A local load scenario (`tests/load/requests.js` with
  `scripts/perf/run-baseline.ps1 -Script requests`); the figures go to
  `docs/evidence`, labelled as local.

## 10. Record the decision

An ADR (`docs/decisions/0033-requests-module.md`) with what the module
does, its data, its rules, access, interface, observability, and how to
remove it.
