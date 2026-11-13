# ADR-0030: CI security checks and three browser engines

- Status: Accepted
- Date: 2026-11-13

## Context

Phase 8 (Testing & CI/CD). The Phase 0 standards ask for pinned CI actions,
Semgrep, Gitleaks, dependency audits and a ZAP baseline. Browser tests ran
in Chromium only, so Firefox and Safari were untested (Phase 7 residual
risk). The repository is public, so every commit in its history is public.

## Decision

- A `security` workflow beside `tests`: Gitleaks over the whole history,
  Semgrep (php, typescript, react, javascript, secrets rule sets), `composer
  audit` and `npm audit` at any severity, dependency review on pull
  requests (advisories and copyleft licences that do not fit a template),
  and a ZAP baseline scan of the signed-out pages. The scan runs against
  the application as a server runs it: the `deploy/` Nginx and PHP-FPM
  templates, its own user, a release under `/srv` with shared storage,
  HTTPS and `APP_ENV=production` with the environment checks on
  (`scripts/ci/production-like.sh`; since batch 8.3 `scripts/ci/server-setup.sh` and the release deployed with `scripts/deploy/deploy.sh`, ADR-0032). Scanning `php artisan serve` instead
  reported headers missing on assets that Nginx serves in production, and
  would never have tested the templates. It also runs weekly, because advisories
  appear without any change here.
- Every alert fails. A false positive is ignored by its exact fingerprint
  or rule id, with a reason (`.gitleaksignore`, `.zap/rules.tsv`).
- Tools are pinned: actions by commit SHA, the ZAP image by digest,
  Gitleaks by version and published checksum, Semgrep by exact version.
  Dependabot proposes grouped updates for actions, Composer and npm, only
  for releases at least two weeks old.
- Browser tests run in Chromium, Firefox and WebKit as separate CI jobs, one
  engine per run, each from a fresh database.
- `CiWorkflowsTest` keeps the workflows to the rules in
  `docs/standards/ci.md` (pinning, read-only permissions, time limits, no
  `pull_request_target`, no persisted checkout credentials).

## Findings on adding Firefox and WebKit

- `waitForLoadState('networkidle')` sometimes never settled in Firefox
  although no request was open (measured: two of twelve page loads, with
  an empty list of open requests). The tests now wait for what they need:
  the load event, the Inertia root drawn, the fonts (`lib/ready.ts`).
- With axe having run, polling the user panel from Playwright while it
  unmounts crashed the page in Playwright's Firefox, every time, in both
  languages. The same close without axe, or with the wait done inside the
  page, never crashed. It is a fault of the test browser's automation
  layer, not of the application: that test waits inside the page.

## Findings of the first ZAP scans

- Fixed: `Cross-Origin-Resource-Policy: same-origin` and
  `Cross-Origin-Embedder-Policy: require-corp` on every response (not
  while the Vite dev server runs); `X-Powered-By` removed by the
  application as well as by the FPM template; the Nginx `/build/` block
  sends CORP and Permissions-Policy too.
- Found by serving the templates for real: `http2 on;` stopped Nginx from
  starting on Ubuntu 24.04 (Nginx 1.24 has no such directive); HTTP/2 is
  now a `listen` parameter, which every version accepts. The icons and
  robots.txt at the top of `public/`, and the built assets, lacked HSTS,
  CORP and Permissions-Policy, which PHP sends but Nginx serves these
  files itself; both locations now send them (DeployTemplatesTest).
- The scan uses a dotted host name (`template.test`): Java clients send no
  server name for `localhost`, and the template rightly refuses TLS to
  unnamed hosts.
- By design, kept with a reason in `.zap/rules.tsv`: the readable
  XSRF-TOKEN cookie, the theme choice in localStorage, ZAP's note that the
  site is a JavaScript application, and `style-src 'unsafe-inline'`
  (ADR-0016, residual risk).

## Other defects found on the way

- WebKit: focus did not return to a button clicked to open the search or
  a panel (Safari does not focus clicked buttons, and a click inside a
  focusable table focuses the table): `lib/opener.ts` remembers the
  control pressed, loaded at start-up.
- The activity timeline showed `user.invited` as code; it now reads as
  words, and AuditLabelsTest fails for any recorded action without a label.
- The table search synced its text in an effect, leaving one render where
  the old text could be sent again (suspected in one WebKit failure); it
  now follows the server during render.
- Two Pest assertions passed a message where Pest expects a value
  (`toContain`, `not->toHaveKey`); the second would have let a forbidden
  trigger through. Both now assert on booleans, and each was checked
  against a planted violation.

## Retries (added in batch 8.3)

- On CI only, a browser test that fails is run once more; one that then
  passes is reported as flaky in the run summary and annotations, so it
  stays visible. Reason: one WebKit run stalled waiting for a button to be
  "stable" on an unchanged page, after many green runs. Locally there are
  no retries. Package sources used by `server-setup.sh` (apt, the PHP
  PPA) get three attempts: one run failed adding the PPA.

## Consequences

- A new advisory in any dependency fails the weekly run and blocks merges
  until the dependency is updated or the advisory is assessed.
- CI takes longer in total, but the browser engines run in parallel.
- ZAP needs Docker and runs in CI only; there is no local equivalent here.

## Security implications

Secrets, vulnerable code patterns and vulnerable dependencies are caught
before merge. Pinning and the update cooldown reduce the risk of a
compromised action or package reaching the pipeline.

## Performance implications

None at run time.

## Reversal or migration strategy

Each job is independent and can be removed on its own; the rules test
lists what each one guarantees.
