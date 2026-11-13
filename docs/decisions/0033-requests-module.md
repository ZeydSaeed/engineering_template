# ADR-0033: The Requests module, the golden path

- Status: Accepted (9.1 domain, data, actions; 9.2 interface and
  integration; observability follows in 9.3)
- Date: 2026-11-14

## Context

Phase 9 builds one business module end to end, so every layer of the
template is shown working together and future modules have a model to
copy (Phase 0 decision 9): a person drafts a request, submits it, and
someone else approves or rejects it, with an attachment.

## Decision

**Module layout** (`app/Modules/Requests`, ADR-0001, ADR-0004)

- `Domain`: `RequestStatus` (the state machine: draft → submitted →
  approved | rejected; only these moves; the last two final) and
  `Decision` (approve or reject; a rejection needs a reason).
- `Models`: `ApprovalRequest`, bigint id inside, UUIDv7 in URLs, morph
  alias `approval_request` in audit records, files and notifications.
- `Application/Actions`: `CreateRequest`, `UpdateDraft`, `SubmitRequest`,
  `DecideRequest`. Application jobs and notifications for the outbox.
- `Database`: the migration and the factory, loaded by
  `RequestsServiceProvider`, which declares everything the module adds
  (migrations, morph alias, outbox handlers). Registered in
  `bootstrap/providers.php`; removing the module is removing that line
  and the folder.

**Data** (`approval_requests`)

- `timestamptz`; `amount numeric(12,2)`; RESTRICT foreign keys to users
  (a person with requests cannot be deleted); an index for each read path
  (my requests by date; the approvers' queue by submission time) and for
  each foreign key.
- The state machine is repeated as CHECK constraints: allowed statuses;
  submitted exactly when not a draft; decided (by someone, at a time)
  exactly when approved or rejected; decider and decision time together;
  decided after submitted; a rejection has a reason; nobody decides their
  own request; amount not negative; a title of at least three characters.
  NULL is named in each rule: a CHECK passes when its expression is NULL
  (the first version of the rejection rule let a NULL reason through; the
  constraint test found it).

**Rules in the actions**

- Anyone signed in may create and submit their own requests; only the
  requester edits a draft, and only from the version they opened
  (`lock_version`, optimistic locking for edits by people).
- Deciding needs `requests.decide`, checked again inside the
  transaction; never on one's own request; the row is locked and its state
  read again under the lock, so of two approvers acting at once one
  decides and the other is told it is already decided.
- Submitting and deciding are idempotent for a repeated click.
- Every change is audited (`request.created|updated|submitted|approved|
  rejected`, each with a label in the activity timeline) and each
  submission or decision is announced through the outbox in the same
  transaction (`requests.submitted` → approvers; `requests.decided` →
  the requester), as database notifications written exactly once.

**Access** (ADR-0014)

- `requests.view_all` and `requests.decide`; a new `approver` role with
  both; administrators get `requests.view_all` only (deciding is the
  approvers' work). Written in the permission matrix.

**Interface and integration** (batch 9.2)

- `Http`: `RequestPolicy` (view: requester or `requests.view_all`;
  update and submit: requester of a draft; decide: `requests.decide`, not
  one's own, submitted), thin controllers, form requests (live checks with
  Precognition), `RequestsTable` (server-side, ADR-0026), a presenter
  that sends plain values and what the viewer may do. Submitting and
  deciding authorise viewing only and leave the rest to the action, which
  answers precisely ("already decided") instead of a 403, and makes a
  repeated click harmless.
- Routes carry the UUID only, constrained to the UUID format (a malformed
  one is a 404, not a database error); the internal id never reaches the
  page.
- Files: added by the requester while the request is a draft (so what is
  decided on cannot change), through FileStore (scanned; served only when
  checked, only through the request they belong to).
- The history panel shows what was done _to_ the request
  (`ActivityTimeline::for(..., includeActions: false)`); `true` would add
  what a _person_ with the same id did.
- Pages live in the module, `resources/js/modules/requests/pages`, named
  `modules/requests/pages/<page>` for Inertia (`app.tsx`, the root view's
  preload, `config/inertia.php` and the bundle budget all know the rule).
- The core application knows the module only through `Contracts`
  (`RequestsOverview`, for the dashboard widget) and its route helpers
  (navigation, notification links).
- Notifications (`request_submitted|approved|rejected`) read as sentences
  in the reader's language and lead to the request.

## Consequences

- The module is a worked example of every rule in
  `docs/architecture/README.md`; deptrac and the module boundary tests
  now check real code.
- Tests: the state machine (unit); every rule of the actions; the
  constraints refusing bad rows written past the actions (PostgreSQL); a
  real race of two approvers (five rounds) and of four submissions.

## Security implications

Separation of duties is enforced twice (action and constraint);
permissions are re-read inside the transaction; audit and announcements
cannot diverge from the change.

## Performance implications

Each action is one short transaction on one locked row; reads use the two
indexes. Measured in batch 9.3.

## Reversal or migration strategy

Remove the provider line, the folder and the migration; the platform does
not depend on the module.
