# Architecture

Status of this document: describes what is implemented as of Phase 9
(checked in the Phase 10 readiness review). What only a target host can
provide is marked **Planned** where it is described (operations guides).

## Style

A modular monolith (ADR-0001): one Laravel application, one deployment, one
database. Shared capabilities live in `app/Platform`; each business area is a
module in `app/Modules/<Name>`. The first, `Requests` (ADR-0033), is the worked
example of every rule below; `building-a-module.md` walks through adding
one, step by step.

```
app/
  Access/                      Permission and Role enums of this application (ADR-0014)
  Platform/                    shared core, no business rules
    Access/                    role assignments, permission checks (ADR-0014)
    Audit/                     tamper-evident audit chain (ADR-0013)
    Backup/                    encrypted backups and restore drills (ADR-0022)
    Console/                   platform commands (backup, outbox relay, audit check)
    Environment/               boot-time configuration checks (ADR-0010)
    Exceptions/                DomainRuleViolation (ADR-0008)
    Files/                     FileStore: scanned uploads (ADR-0020)
    Http/                      middleware, health and metrics, outbound calls (ADR-0009, 0016, 0021, 0023)
    Identity/                  the second factor, per user (ADR-0015)
    Jobs/                      queues by workload (ADR-0019)
    Localization/              Arabic and English (ADR-0024)
    Logging/                   sensitive-data masking (ADR-0009)
    Observability/             /metrics, module MetricsSource (ADR-0023)
    Outbox/                    transactional outbox and relay (ADR-0019)
    Preferences/               per-person settings (ADR-0028)
    Tables/                    server-side tables (ADR-0026)
    PlatformServiceProvider.php
  Modules/<Name>/              one business area (only the folders it needs)
    Contracts/                 the module's public API: interfaces, DTOs, events
    Domain/                    enums (state machines), value objects, rules
    Models/                    Eloquent models
    Application/               Actions (writes) and Queries (reads)
    Infrastructure/            adapters for external services (ADR-0007)
    Http/                      controllers, form requests, resources
    Database/                  migrations, factories, seeders
resources/js/
  modules/<name>/              pages and components of one module
```

The starter kit's own code (`app/Http`, `app/Models/User`, `app/Actions/Fortify`,
`app/Providers`) stays where Laravel puts it: the application's own pages
(dashboard, settings, administration) live in `app/Http` and reach modules
only through their `Contracts`.

## Dependency rules (ADR-0004)

| Rule                                                                                                     | Checked by                                                     |
| -------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------- |
| Layers: Http → Application → Domain; Infrastructure implements ports; Domain uses no HTTP, facades or DB | `deptrac.yaml`                                                 |
| Platform never uses a module                                                                             | Pest `tests/Architecture/PlatformRulesTest.php` and deptrac    |
| A module uses another module only through its `Contracts`                                                | Pest `ModuleBoundariesTest.php` (one rule per pair of modules) |
| No cycles between modules                                                                                | Pest `ModuleBoundariesTest.php`                                |
| Frontend modules do not import each other                                                                | Pest `ModuleBoundariesTest.php`                                |
| `env()` only in `config/`; no debug or insecure PHP functions                                            | Pest presets and rules                                         |
| Models do not resolve services; controllers do not use the DB facade                                     | Pest                                                           |

Evidence that each rule fails when broken:
`docs/evidence/phase-1/deliberate-violations.txt`, and again in the Phase 10
review (`docs/evidence/phase-10/readiness-review.md`).

### Acceptable and unacceptable code

```php
// Acceptable: controller validates, authorizes, calls one action.
public function store(SubmitRequest $request, SubmitRequestAction $submit): RedirectResponse
{
    $submit->handle($request->validated(), $request->user());

    return back();
}

// Unacceptable: query and business rule inside the controller.
public function store(Request $request): RedirectResponse
{
    DB::table('requests')->where('id', $request->id)->update(['status' => 'approved']);
}
```

```php
// Acceptable: another module's public contract.
use App\Modules\Approvals\Contracts\ApprovalLookup;

// Unacceptable: another module's internals.
use App\Modules\Approvals\Models\Approval;
```

## Cross-cutting behaviour (implemented)

| Concern              | Implementation                                                                                                                                                                                                                                 |
| -------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Request correlation  | `AssignRequestId`, outermost middleware: inbound `X-Request-Id` reused when it matches `^[A-Za-z0-9._-]{8,64}$`, else a UUIDv7; stored in `Context` (travels into queued jobs and log records) and returned on every response, errors included |
| Logs                 | `json` channel: JSON lines, daily files, `LOG_DAILY_DAYS` retention; `json_stderr` for containers. Every file and stderr channel masks the keys in `config/platform.php` at any depth                                                          |
| Business rule errors | `DomainRuleViolation`: never reported; 422 JSON with `errors[field]` for API clients, redirect back with the error for browser and Inertia requests                                                                                            |
| Configuration safety | `EnvironmentValidator`, run at boot in production and staging: refuses debug mode, an empty key, insecure session cookies, a non-HTTPS URL and denied database names                                                                           |

## Where the rest is decided and described

| Concern                                        | Decision           | Guide                                             |
| ---------------------------------------------- | ------------------ | ------------------------------------------------- |
| Database roles, schema conventions             | ADR-0012           | `docs/standards/database.md`                      |
| Identity, second factor, access control, audit | ADR-0013 to 0015   | `docs/standards/security.md`                      |
| Security headers, CSP, network, outbound calls | ADR-0016, ADR-0021 | `docs/standards/security.md`                      |
| Performance, caching                           | ADR-0017, ADR-0018 | `docs/standards/performance.md`, `caching.md`     |
| Queues and outbox                              | ADR-0019           | `docs/operations/queues.md`                       |
| Files                                          | ADR-0020, ADR-0027 | `docs/standards/security.md`                      |
| Backups and recovery                           | ADR-0022           | `docs/operations/recovery.md`                     |
| Metrics, alerts, runbooks                      | ADR-0023           | `docs/operations/monitoring.md`, `docs/runbooks/` |
| Design system and interface                    | ADR-0024 to 0029   | `docs/design-system/`                             |
| CI, release, deploy and rollback               | ADR-0030 to 0032   | `docs/standards/ci.md`                            |
| Modules                                        | ADR-0033           | `building-a-module.md`                            |
