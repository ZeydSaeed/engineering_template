# CI and pipeline standard

How changes are checked before they reach `main` (ADR-0030). Local
commands give the same answers as CI.

## Workflows

| Workflow       | Runs on                                    | Jobs                                                                                                      |
| -------------- | ------------------------------------------ | --------------------------------------------------------------------------------------------------------- |
| `tests.yml`    | push to `main` and `phase-*`, pull request | SQLite suite and static checks; PostgreSQL 18 with Redis and ClamAV; browsers (Chromium, Firefox, WebKit) |
| `release.yml`  | push to `main` and `phase-*`, on demand    | build the artifact twice and compare, verify, SBOM; on `main` a provenance attestation (ADR-0031)         |
| `security.yml` | the same, plus weekly and on demand        | secrets (whole history), static analysis, dependency advisories, ZAP baseline                             |

A change merges only when every job is green.

## Rules (CiWorkflowsTest)

- Every action is pinned to a full commit SHA, with the version in a
  comment; every container image is pinned to a digest. Dependabot proposes
  updates; nothing updates itself.
- `permissions: contents: read` at the top of every workflow; a job that
  needs more asks for it itself.
- `actions/checkout` with `persist-credentials: false`.
- No `pull_request_target` or `workflow_run`: code from a fork never runs
  with this repository's rights.
- Every job has `timeout-minutes`.
- Tools downloaded in a job are pinned and checked against their published
  checksum (Gitleaks) or installed at an exact version (Semgrep).
- New versions of tools and dependencies wait at least two weeks
  (Dependabot `cooldown`), so a compromised release is usually withdrawn
  before it arrives.

## Security checks

| Check        | Tool                                                                                                                                               | Fails on                                             | Locally                                                                                               |
| ------------ | -------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------- | ----------------------------------------------------------------------------------------------------- |
| Secrets      | Gitleaks 8.30.1, every commit                                                                                                                      | any finding not in `.gitleaksignore`                 | `.tools\gitleaks\gitleaks.exe git --redact .`                                                         |
| Code         | Semgrep 1.182.1: php, typescript, react, javascript, secrets rule sets                                                                             | any finding                                          | `.tools\semgrep\Scripts\semgrep.exe scan --metrics=off --error --config p/php ...` (see security.yml) |
| Dependencies | `composer audit`, `npm audit`                                                                                                                      | any advisory, any severity                           | the same commands                                                                                     |
| New packages | dependency review (pull requests)                                                                                                                  | an advisory, or a GPL/AGPL/SSPL licence              | (GitHub only)                                                                                         |
| Web          | ZAP 2.17.0 baseline, passive, signed out, against Nginx and PHP-FPM from `deploy/` over HTTPS in production mode (`scripts/ci/production-like.sh`) | any alert not set to IGNORE/INFO in `.zap/rules.tsv` | needs Docker and a Linux runner; CI only                                                              |

A false positive is ignored by its exact fingerprint (Gitleaks) or rule id
(ZAP), with the reason on the line above, never by turning a rule off for
everything. A real finding is fixed, not ignored.

## Browsers

`npm run test:browser` runs Chromium; `npx playwright test
--project=firefox` (or `webkit`) runs another engine. One engine per run,
because the tests change data and each run starts from a fresh database.
Browsers live in `.tools\ms-playwright` (`scripts\env.ps1`).
