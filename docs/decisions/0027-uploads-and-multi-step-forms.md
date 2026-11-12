# ADR-0027: Uploads with progress, and multi-step forms

- Status: Accepted
- Date: 2026-11-13

## Context

Improvements #14 (drag and drop), #15 (progress, cancel, retry) and #6
(multi-step forms with progress) need real flows to be proved: a person's
own documents, and an administrator inviting a person.

## Decision

**Uploads** (`components/file-upload.tsx`, `settings/documents`)

- Drop files or press "Choose files" (the keyboard and screen-reader way
  in). Files go one at a time (an Inertia visit replaces the one before
  it) to the route as `file`, each with a `<progress>` named after the
  file, cancel, and retry after a failure; outcomes are announced in a
  polite live region.
- The browser's type and size checks only answer early; FileStore
  (ADR-0020) decides: type from the bytes, random stored name, scan before
  download. A refusal comes back as the file field's error (the existing
  DomainRuleViolation rendering).
- Documents are attached to their owner; listing and download are the
  owner's alone (anyone else gets 404); uploads are limited to 20 a minute
  per person; download only once verified.
- The queue lives in a ref, so no side effect runs inside a React state
  updater (Strict Mode calls updaters twice; a side effect there would
  upload twice).

**Multi-step forms** (`components/multi-step-form.tsx`)

- A step indicator (`aria-current="step"`, "Step n of m"), each step a
  section whose heading takes focus when the step changes. Every step
  stays in the form, so earlier answers are submitted with the last.
- "Next" sends one explicit Precognition request for the step's fields to
  the form's own route (same rules as the submission, CSRF header from
  the XSRF-TOKEN cookie) and moves on only on a 204; a 422 puts the
  errors on the fields and focuses the first. It does not use the shared
  validator for this: in Inertia 3.11 `validate(config)` loses the
  callbacks, and the validator delays a check that follows another and
  skips one whose data it has seen, which made the result late or absent.
- Fields do not check on blur when focus moves to the step buttons
  (`data-checks-fields`): the blur check succeeding removed an error line,
  the layout moved, and the click on "Next" was lost between press and
  release, for people as well as tests.
- If the final submission finds an error, the form shows the first step
  with an erroneous field.

**Invitations** (`InviteUser`, `UserInvitationController`)

- Administrators only (roles.manage), throttled to 30 requests a minute
  (step checks included). The account starts with a random 64-character
  password nobody knows and an unverified address; a role is granted
  through AssignRole (audited); `user.invited` is audited.
- The email carries a password-reset link (auth.passwords.users.expire,
  60 minutes); setting the password verifies the address
  (`ResetUserPassword`), since the link arrived by email. The person sets
  up a second factor on first sign-in, as everyone must.

## Consequences

Any page can offer uploads with one component; any long form can be split
into steps by listing each step's fields.

## Security implications

No upload rule moves to the browser; documents are per owner; invitations
create no usable password; the step checks are rate-limited with the
submission.

## Performance implications

Uploads stream through FileStore as before; one file at a time keeps
server memory flat. The upload and form components load only on the pages
that use them (bundle budget per page).

## Reversal or migration strategy

The invitation link expiry follows the password-reset expiry; a longer
invitation window needs its own token table.
