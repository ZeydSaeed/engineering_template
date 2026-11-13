# Architecture

Status of this document: describes what is implemented as of Phase 1. Planned
parts are marked **Planned**.

## Style

A modular monolith (ADR-0001): one Laravel application, one deployment, one
database. Shared capabilities live in `app/Platform`; each business area is a
module in `app/Modules/<Name>`. The first, `Requests` (ADR-0033), is the worked
example of every rule below.

```
app/
  Platform/                    shared core, no business rules
    Environment/               boot-time configuration checks (ADR-0010)
    Exceptions/                DomainRuleViolation (ADR-0008)
    Http/Middleware/           AssignRequestId (ADR-0009)
    Logging/                   sensitive-data masking (ADR-0009)
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
`app/Providers`) stays where Laravel puts it until Phase 2 reorganises identity.

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
`docs/evidence/phase-1/deliberate-violations.txt`.

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

## Planned

Security headers and HSTS check (Phase 2), identity and MFA (Phase 2), audit
(Phase 2), queues by workload and outbox (Phase 4), files (Phase 5),
observability (Phase 6), design system (Phase 7).
