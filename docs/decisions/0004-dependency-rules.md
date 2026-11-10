# ADR-0004: Dependency rules and their enforcement

- Status: Accepted
- Date: 2026-11-11

## Context

Module boundaries kept only by convention erode: controllers start querying
the database, models reach into services, modules read each other's tables.

## Problem

Make the intended dependency direction a build failure when it is broken.

## Options

1. Code review only.
2. Pest architecture tests (`arch()`).
3. Pest architecture tests plus a dependency analyser (deptrac) for
   module-to-module rules and cycles.

## Decision

Option 3. Rules:

1. `Http → Application → Domain`. `Infrastructure` implements interfaces
   declared in `Domain` or `Application`.
2. `Domain` does not use `Illuminate\Http`, facades or `DB`.
3. A module never uses another module's `Models` or `Infrastructure`; only its
   `Contracts` and events.
4. `Platform` never uses `Modules\*`.
5. Controllers do not use `DB` or raw queries.
6. `env()` only in `config/`; models do not call `app()` or `resolve()`.
7. No dependency cycles between modules.
8. Frontend: `resources/js/modules/a` never imports `resources/js/modules/b`.

## Consequences

- A violation fails CI; an exception needs an ADR and an explicit allow entry.
- Rules are tested by a deliberate violation during Phase 1 acceptance.

## Security implications

Keeps authorization and data access on reviewed paths.

## Performance implications

None at runtime; a few seconds in CI.

## Reversal or migration strategy

Rules are data in `tests/Architecture` and `deptrac.yaml`; relaxing one is a
reviewed change.
