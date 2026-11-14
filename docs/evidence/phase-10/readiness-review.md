# Phase 10: readiness review

Date: 2026-11-14. Reviewed: `main` at `d57d073` (Phases 1 to 9), on the
owner's development machine (Windows 10, PHP 8.5.12 on Herd, PostgreSQL
18.2, Node 24) and in GitHub Actions (Ubuntu). A review, not an
implementation: the findings below are proposals; nothing was changed to
make an area pass.

Statuses: PASS · PASS WITH CONDITIONS · HOLD · NOT TESTED · NOT VERIFIED ·
REQUIRES APPROVAL (Phase 0 decisions, section 6).

## Verdict per area

| Area         | Status               | In one line                                                                                       |
| ------------ | -------------------- | ------------------------------------------------------------------------------------------------- |
| Architecture | PASS                 | Rules enforced twice (deptrac and Pest) and shown to fail on a planted violation                  |
| Security     | PASS WITH CONDITIONS | Every automated control green; DAST is a signed-out passive baseline only; one accepted CSP risk  |
| Performance  | PASS WITH CONDITIONS | Budgets hold; every load figure is from one laptop; the target host is not measured               |
| Reliability  | PASS WITH CONDITIONS | Backups, drill, deploy and rollback proven; drill not re-run since Phase 6; PITR not built        |
| UI           | PASS WITH CONDITIONS | axe, RTL and three engines green; one reflow defect at 320 px in Arabic; manual checks NOT TESTED |
| Docs         | PASS WITH CONDITIONS | 33 ADRs, a runbook for every alert; four documents state things that are no longer true           |

No area is HOLD: nothing found blocks building a first real module on the
template. The conditions are what must be done before a production
deployment of an application built from it.

## Gates run for this review (main, d57d073)

| Gate                                                                      | Result                                                              |
| ------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| `composer ci:check` (SQLite: vp check, tsc, Pint, PHPStan, deptrac, Pest) | 315 passed, 38 skipped; 0 errors, 0 violations                      |
| `composer test:pgsql`                                                     | 351 passed, 2 skipped                                               |
| `npm run build`, `npm run budget`                                         | base 184.3 kB of 185; every page within +35 kB                      |
| Playwright, full suite, Chromium / Firefox / WebKit                       | 245/245 in each                                                     |
| `composer audit --locked`, `npm audit` (2026-11-14)                       | no advisories                                                       |
| CI on main after the merge (runs 43515368843, 43515368814, 43515368924)   | release with attestation, security and tests (three engines): green |

## 1. Architecture: PASS

- Modular monolith with `app/Platform` and `app/Modules` (ADR-0001,
  ADR-0004). deptrac checks layer direction; Pest architecture tests check
  that the platform never uses a module, that modules meet only through
  `Contracts`, that there are no cycles, and that frontend modules are
  independent.
- Shown to work: a class written into `app/Platform` that imports a
  Requests model was refused by both (deptrac: 1 violation,
  DependsOnDisallowedLayer; Pest: PlatformRulesTest failed). The class was
  deleted afterwards.
- The Requests module uses every layer and reaches the core only through
  `Contracts`, route helpers and tagged extension points (outbox handlers,
  tables, metrics).
- Limit: with one module, the rules between two modules are written and
  tested as rules, not exercised by a real second module.

## 2. Security: PASS WITH CONDITIONS

Green: a second factor required for every signed-in user; roles and
permissions with a written permission matrix; a tamper-evident audit chain
with verification and an alert; security headers and a nonce-based script
CSP (SecurityHeadersTest); rate limits; uploaded files scanned before they
are served; separate database roles with a connection limit on the runtime
role; no secrets in git; Gitleaks, Semgrep, dependency audits, dependency
review and ZAP in CI; signed release attestation and an SBOM. Every ZAP
alert is classified with its reason in `.zap/rules.tsv`.

Conditions

1. DAST is ZAP's passive baseline, signed out, on the CI runner. No
   authenticated or active scan has been run, and nothing has been tested
   against a real host. Before production: an authenticated active scan on
   staging.
2. `style-src 'unsafe-inline'` (ADR-0016): an accepted residual risk (CSS
   injection needs an HTML injection first). Remove it when the UI library
   allows.
3. Repository protections (branch protection, required reviews, secret
   scanning settings) need an authenticated API: NOT VERIFIED here.
4. Dependabot PR #1 (npm group) is open, waiting for the owner.

## 3. Performance: PASS WITH CONDITIONS

Green: query budgets per page (QueryBudgetTest); 10,000-row tables (users
and requests) within their budget of 12 queries (requests: 6 to 10) and tens of
milliseconds, with index
plans checked; the bundle budget enforced on every build.

Conditions

1. Every load figure comes from one Windows laptop (Intel i5-7300U, 4
   threads) running the application, database and load generator
   together. It saturates at 50 virtual users with real staff pages (p50
   5.83 s, CPU 89 % on average). That is the laptop's limit, not a
   capacity: measure on the target host before sizing.
2. Database time per request is dominated by opening a connection (one per
   request, Phase 3). A pooler or persistent connections must be measured
   on the target host (ADR-0017).
3. Bundle base at 184.3 kB of 185: the next module will need room.

## 4. Reliability: PASS WITH CONDITIONS

Green: backups with three separate keys, checksums and a restore drill
that compares row counts (Phase 6); liveness and readiness endpoints;
metrics and 21 alerts, each with a runbook (AlertRulesTest); the outbox and
queues by workload; deploy, smoke test and rollback rehearsed on every
release build in CI (Phase 8).

Conditions

1. The restore drill last ran in Phase 6, on 15 tables. The schema has
   grown since. Re-running it needs the temporary
   `engineering_template_restore` database: REQUIRES APPROVAL.
2. Point-in-time recovery (WAL archiving) and the Prometheus and
   Alertmanager stack with its exporters are Planned, NOT TESTED: they
   belong to the target host.
3. A restore on a separate machine: NOT TESTED.

## 5. UI: PASS WITH CONDITIONS

Green: Arabic (right to left) and English; axe with no violations on every
page the browser tests open; keyboard, focus and dialogs tested in three
engines; contrast tokens tested; all 28 design improvements Done
(`docs/design-system/improvements.md`).

Reflow check (this review; a script outside the repository, run against
the local site with development data): 14 pages, in Arabic and English, at
320 px and at 640 px (a 1280 px window at 200 % zoom). No page scrolls
sideways anywhere. One defect:

- **Arabic, 320 px, `/requests` and `/admin/users`:** the table's
  pagination row does not wrap. Its outermost page button is cut off by 6
  px and 14 px at the left edge and cannot be scrolled to (WCAG 1.4.10).
  English, and both languages at 640 px, are fine. Proposed fix: let the
  pagination row wrap, and add a 320 px Arabic case to the browser tests.

Conditions

1. The reflow defect above.
2. Every manual check in `docs/design-system/manual-tests.md` is NOT
   TESTED: screen readers (NVDA, VoiceOver), the whole application by
   keyboard only, speech input with shortcuts, real phones and tablets,
   real Safari, Windows high contrast. These need a person.

## 6. Documentation: PASS WITH CONDITIONS

Green: 33 ADRs, all Accepted, each with consequences and a way back; a
runbook for every alert; standards, operations guides and a module guide;
evidence for every phase; no broken internal links (checked over 56
Markdown files).

Conditions: four documents state things that are no longer true.

1. `docs/architecture/README.md` says it describes the state "as of Phase
   1"; its Planned section lists work done in Phases 2 to 7, and its
   cross-cutting table stops at Phase 1.
2. `docs/standards/security.md` lists file upload security, SAST and DAST,
   the SBOM and security alerts as Planned (done in Phases 5, 6 and 8).
3. `docs/standards/database.md` says `lock_version` is "Planned with the
   first module" (done in Phase 9).
4. `docs/operations/capacity.md` quotes 9 to 10 req/s from Phase 3, from
   the measurement corrected in Phase 9 (staff were signed out); the
   corrected run gives 5.2 req/s at 50 users.

## Proposed next work (each needs the owner's approval)

| #   | Work                                                                    | Acceptance                                                          |
| --- | ----------------------------------------------------------------------- | ------------------------------------------------------------------- |
| 1   | Correct the four documents                                              | Each statement matches the code and the evidence; links clean       |
| 2   | Pagination wraps at 320 px; a browser test at 320 px in Arabic          | Reflow clean on all 14 pages; the new test fails before the fix     |
| 3   | Re-run the restore drill (temporary database, dropped afterwards)       | Drill PASSED, rows matching in every current table                  |
| 4   | Manual checks by a person (screen reader, devices, contrast)            | Each row in manual-tests.md says who, when, on what, and the result |
| 5   | On a target host: load, pooler, exporters, PITR, authenticated ZAP scan | Figures and drills recorded as for this machine                     |

## After the follow-ups (2026-11-14)

The owner approved all five proposals, to be done except what cannot be,
and never touching External_Link locally or on its server. Details and
figures: `docs/evidence/phase-10/followups.txt`. "Target host" work ran on
disposable CI runners (`.github/workflows/operations.yml`), not on any of
the owner's servers.

| #   | Proposal                   | Done                                                                                                                                   | Left                                                                                     |
| --- | -------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| 1   | Correct the four documents | All four corrected                                                                                                                     | —                                                                                        |
| 2   | Reflow                     | Pagination wraps; reflow.spec.ts at 320 px for 15 pages, both languages; a label-in-name fault (WCAG 2.5.3) found and fixed on the way | —                                                                                        |
| 3   | Restore drill              | On every push, onto a separate PostgreSQL server: 19 tables, 100 files, audit chain, secrets                                           | A drill on the owner's machine needs the database superuser to create the drill database |
| 4   | Manual checks              | Automated what can be: keyboard in three engines; forced colors, which found focus invisible in Windows high contrast (fixed)          | Screen readers, speech input, real devices and Safari, real contrast themes: a person    |
| 5   | Target host                | PITR rehearsed; promtool and a real Prometheus; load on Linux with and without PgBouncer; an active ZAP scan signed in                 | A real host: pgBackRest with storage, Alertmanager receivers, exporters, capacity        |

Verdicts now

| Area         | Status               | What changed                                                                                                        |
| ------------ | -------------------- | ------------------------------------------------------------------------------------------------------------------- |
| Architecture | PASS                 | —                                                                                                                   |
| Security     | PASS WITH CONDITIONS | Active signed-in DAST on every push, 0 High; conditions: staging scan, the CSP residual risk, ZAP 10024             |
| Performance  | PASS WITH CONDITIONS | Linux figures (p50 8 to 25 ms; PgBouncer halves database time); a real host still to size                           |
| Reliability  | PASS WITH CONDITIONS | Restore drill onto a second server and PITR proven on every push; condition: pgBackRest with storage on a real host |
| UI           | PASS WITH CONDITIONS | Reflow and high-contrast faults fixed and tested; only person-run checks remain                                     |
| Docs         | PASS                 | The four documents corrected; recovery, monitoring, capacity and security guides record the new evidence            |
