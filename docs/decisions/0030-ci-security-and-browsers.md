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
  (`scripts/ci/production-like.sh`). Scanning `php artisan serve` instead
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
- By design, kept with a reason in `.zap/rules.tsv`: the readable
  XSRF-TOKEN cookie, the theme choice in localStorage, ZAP's note that the
  site is a JavaScript application, and `style-src 'unsafe-inline'`
  (ADR-0016, residual risk).

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
