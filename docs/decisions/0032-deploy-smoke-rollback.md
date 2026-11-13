# ADR-0032: Deploy, smoke test and rollback of release artifacts

- Status: Accepted
- Date: 2026-11-13

## Context

Phase 8 acceptance: deploy and rollback rehearsed without touching any
real server, and a manual staging deployment. Releases are artifacts
(ADR-0031); the server layout is the one the deploy templates assume:
`/srv/APP/{releases/<sha>, shared/{storage,.env}, current}`.

## Decision

- `scripts/deploy/deploy.sh <artifact>`, run as root on the server:
  verify (ADR-0031), unpack once per commit, link the shared storage and
  `.env`, migrate, build the caches as the application user, switch
  `current` with one rename (`ln -s` to a temporary name, then `mv -T`),
  reload PHP-FPM, restart the queue workers, smoke-test. If the smoke test
  fails it switches back to the previous release, checks it and exits 1.
  It keeps the newest five releases (never the live or the previous one)
  and records every step in `/srv/APP/deploys.log`.
- `scripts/deploy/smoke.sh <sha>` goes through Nginx and PHP-FPM on the
  machine itself (`--resolve` to 127.0.0.1 with the site's name): ready,
  serving the expected release, `/up`, the sign-in page with its security
  headers, and the page's main script. `/health/ready` now names the
  release it serves (`RELEASE.json`), so "the new code answers" is checked,
  not assumed. It stays pass/fail on health and is served to the machine
  only.
- `scripts/deploy/rollback.sh [sha]` returns to the previous live release
  (from the log) or a named one, and smoke-tests it. Code only: migrations
  are forward-only and must keep the previous release working.
- Rehearsal: the `release` workflow prepares a CI runner as a server from
  the deploy templates (`scripts/ci/server-setup.sh`) and runs
  `scripts/ci/rehearse-deploy.sh` with the commit's artifact A and two
  releases derived from it: deploy B, deploy A, roll back to B, deploy A
  again, deploy a broken C (fails, back to A by itself), deploy A again
  (no change), checking after each step which release answers. The ZAP
  scan runs against a release deployed the same way.
- `deploy-staging` deploys a commit's artifact over SSH, started by hand
  only, through a GitHub environment the owner configures (reviewers, key,
  host key, target); until then it stops with "Staging is not configured".
  There is no production workflow. CiWorkflowsTest fails if a job with an
  environment can start any other way than by hand, or targets production.

## Consequences

- One procedure for staging, production and the rehearsal; the server
  needs only the artifact and four scripts.
- Rehearsed on disposable GitHub runners, not on this PC: it needs a Linux
  machine with systemd, Nginx and PHP-FPM, and this PC has none without
  installing software outside the workspace.

## Security implications

Only verified artifacts are installed; the deploy key is used by a manual,
reviewed job and removed after it; host keys are pinned. No server is
contacted by any automatic workflow.

## Performance implications

The switch is atomic; requests in flight finish on the old release
(PHP-FPM reload is graceful). Caches are built before the switch.

## Reversal or migration strategy

Deploying from a checkout remains possible by hand; the scripts can be
adopted one server at a time.
