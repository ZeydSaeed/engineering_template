# Phase 0 — approved decisions and backlog

Status: Phase 0 approved by the owner on 2026-11-11. Nothing below is
implemented yet unless marked so.

## 1. Approved decisions

| #   | Decision                                                                                                                                                                                                                                       | Approved |
| --- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------- |
| 1   | Architecture: modular monolith with vertical slices, ports and adapters for external services, selective CQRS (read projections only where a measured read load exists), light tactical DDD. No microservices, Kubernetes or message broker.   | Yes      |
| 2   | Clean-room build on a fresh Laravel skeleton; nothing copied from protected projects.                                                                                                                                                          | Yes      |
| 3   | Workspace: `D:\Projects\Engineering-Template` (created 2026-11-11).                                                                                                                                                                            | Yes      |
| 4   | Local `git init` (done, branch `main`). A GitHub repository or push needs separate approval.                                                                                                                                                   | Yes      |
| 5   | Install tools and packages inside this workspace only.                                                                                                                                                                                         | Yes      |
| 6   | Baseline: PHP 8.5 (fall back to 8.4 only with an ADR if a dependency is incompatible), Laravel 13 with a planned move to 14, PostgreSQL 18, Redis (or Valkey after an ADR), React 19, Inertia 3, TypeScript strict, Tailwind 4, standard Vite. | Yes      |
| 7   | A new local test database for the template only, with new names and roles; no existing database is touched.                                                                                                                                    | Yes      |
| 8   | Performance and monitoring environment: this local machine. Results are local-machine results and must be reported as such.                                                                                                                    | Yes      |
| 9   | Golden-path demo module: a generic "Requests & Approvals" flow (draft → submit → approve/reject, with an attachment).                                                                                                                          | Yes      |
| 10  | MFA is mandatory for staff by default.                                                                                                                                                                                                         | Yes      |
| 11  | Implementation runs in a Claude Code session whose working directory is this workspace.                                                                                                                                                        | Yes      |

## 2. Local machine (checked 2026-11-11, read-only version checks)

| Tool         | Found                                                |
| ------------ | ---------------------------------------------------- |
| PHP          | 8.4.16 (8.5 not installed yet)                       |
| Composer     | 2.9.5                                                |
| Node / npm   | 22.13.0 / 10.9.2                                     |
| Laravel Herd | 1.25.0                                               |
| git          | 2.53.0                                               |
| k6           | 2.2.0                                                |
| psql         | not on PATH (PostgreSQL may be installed; to verify) |
| redis-server | not found                                            |
| Docker       | not found                                            |

## 3. Architecture summary

```
app/
  Platform/          shared core, no business rules
    Security/ Access/ Audit/ Health/ Files/ Jobs/ Observability/ Http/ Support/ Testing/
  Modules/<Name>/
    Domain/          enums (state machines), value objects, specifications, events
    Application/     actions (commands), queries returning DTOs
    Infrastructure/  adapters implementing ports
    Http/            thin controllers, requests, resources
    Database/        migrations, factories, seeders
    Tests/
resources/js/
  platform/          design system (tokens, components), layouts, hooks, lib
  modules/<name>/    pages and module components
```

Dependency rules, enforced by Pest `arch()` and deptrac:

1. `Http → Application → Domain`; `Infrastructure` implements interfaces defined in `Domain` or `Application`.
2. `Domain` uses no `Illuminate\Http`, facades or `DB`.
3. A module never imports another module's `Models` or `Infrastructure`; only its `Contracts` or events.
4. `Platform` never imports `Modules\*`.
5. Controllers never use `DB::`, `whereRaw` or write through models directly.
6. No `env()` outside `config/`; no `app()`/`resolve()` inside models.
7. No cycles between modules.
8. Frontend: `modules/a` never imports from `modules/b` (ESLint restricted paths).

Shared platform capabilities stay inside the application; extraction to a
Composer package only when a second real application needs them.

## 4. Standards to implement (from the Phase 0 report)

- Database: `bigint` keys plus `uuid` v7 for URLs, `timestamptz`, `jsonb`,
  `numeric` for exact values, CHECK constraints for state rules, RESTRICT
  foreign keys, every index documented, a test that fails on an unindexed
  foreign key, three roles (`owner` DDL, `app` DML, `backup`), per-role
  `lock_timeout`, `statement_timeout`, `idle_in_transaction_session_timeout`
  and `CONNECTION LIMIT`, `pg_stat_statements` and `auto_explain`, reversible
  migrations checked by migrate → rollback → migrate, PITR with WAL archiving.
- Concurrency: short transactions, row lock on the aggregate root, documented
  lock order, re-check under the lock, `lock_version` for user edits,
  state-based idempotency, idempotency keys for APIs, unique jobs, concurrency
  tests with real parallel PHP processes.
- Queues by workload (`scan`, `pdf`, `notifications`, `imports`/`exports`,
  `default`), one systemd unit each with `MemoryMax`/`CPUQuota`; optional
  transactional outbox for events that must not be lost.
- Caching: only with a named invalidation owner, maximum staleness and
  sensitivity class; versioned keys; no authenticated response in any shared
  cache; writes and decisions never read from cache.
- Files: private by default, generated names, content sniffing, SHA-256,
  pending → quarantined | verified | rejected, download only when verified,
  fail closed when the scanner or storage is down, orphan sweeps.
- HTTP: timeouts strictly nested (edge > Nginx > FPM > PHP > outbound calls),
  `Server-Timing` to separate application, database and external latency.
- Security: MFA for staff, explicit RBAC with a permission matrix test,
  tamper-resistant audit (REVOKE + trigger + hash chain), separate keys
  (APP_KEY, audit HMAC, backup keys per class), pinned CI actions, SBOM,
  Semgrep, Gitleaks, dependency audits, ZAP baseline.
- Real time: polling first; SSE for progress; WebSockets only with an ADR and
  a proven need.

## 5. Backlog (estimates in working days for one person; not commitments)

| Phase                      | Content                                                                                                                                                                                                      | Acceptance                                                                                | Estimate |
| -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------- | -------- |
| 1 Foundation               | Laravel 13 + PHP 8.5 (or 8.4 with ADR), `Platform/` and `Modules/` skeleton, Pint, Larastan, ESLint, arch tests and deptrac, ADR-0001…0010, environment validator, error handling, JSON logs with request id | Build green locally; arch tests fail on a deliberate violation; every decision has an ADR | 5–8      |
| 2 Database & security      | Schema standards, DB roles, portals and sessions, MFA, RBAC and matrix, audit, headers and CSP, rate limits, secrets                                                                                         | Authorization, separation, headers and audit tests pass; no secrets in git                | 10–14    |
| 3 Performance              | Capacity model, memory budget, cache and query standards, `assertQueryCount`, k6 and exporters on this machine                                                                                               | Baseline documented with environment, data, duration and tool                             | 6–9      |
| 4 Async                    | Workload queues, worker units, outbox, concurrency tests                                                                                                                                                     | No lost job in a crash test; race tests pass                                              | 5–7      |
| 5 Storage & network        | Files module, R2 adapter, Nginx/FPM templates, timeout chain, resilient outbound HTTP adapter                                                                                                                | Scanner and storage failure tests pass; response headers checked                          | 6–8      |
| 6 Observability & recovery | Metrics, dashboards, alerts with runbooks, backups with separate keys, PITR, restore drill                                                                                                                   | Successful isolated restore; every alert has an action                                    | 7–10     |
| 7 Design system            | Tokens, components, layouts, RTL, themes, the 28 UI improvements (MUST first)                                                                                                                                | axe clean; RTL/LTR mobile/desktop screenshots; bundle budget met                          | 12–18    |
| 8 Testing & CI/CD          | Workflows, immutable artifact with SHA and SBOM, manual staging deploy, smoke, rollback                                                                                                                      | Full pipeline green; deploy and rollback rehearsed locally                                | 6–9      |
| 9 Golden path              | Requests & Approvals demo module end to end                                                                                                                                                                  | Every layer represented, tested and observed                                              | 6–8      |
| 10 Readiness review        | Architecture, security, performance, reliability, UI, docs                                                                                                                                                   | Report with PASS/HOLD per area                                                            | 3–5      |

Total: about 66–96 working days.

## 6. Phase report format (Arabic)

1. Executive summary · 2. Scope (workspace and files accessed) · 3. Work
   completed (verified only) · 4. Technical findings · 5. Performance and resource
   analysis · 6. Security analysis · 7. UI/UX analysis · 8. Tests and evidence
   (commands, environment, results, limitations) · 9. Outstanding issues · 10.
   Next steps with acceptance criteria · 11. Approval required.

Statuses: PASS, PASS WITH CONDITIONS, HOLD, BLOCKED, NOT TESTED, NOT
VERIFIED, REQUIRES APPROVAL.

## 7. Open items before or during Phase 1

- Install PHP 8.5 through Herd and verify every dependency on it (else ADR for 8.4).
- Locate the local PostgreSQL 18 installation (psql is not on PATH) and create
  the template's own test database and roles.
- Redis or Valkey on Windows: decide (no Docker here); until then the
  database cache/queue drivers are used locally.
- Confirm Inertia v3 stable release and its supported Laravel adapter version.
- Arabic font licensing for the design system.
