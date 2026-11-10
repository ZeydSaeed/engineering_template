# ADR-0002: Clean-room build

- Status: Accepted
- Date: 2026-11-11

## Context

Earlier read-only assessments of an existing production application identified
useful patterns (portal separation, append-only audit, state machines in enums,
row locking with retries, health probes). That application and another project
are protected: nothing may be read from, copied from or linked to them.

## Problem

Reuse proven ideas without carrying over code, history, identifiers or data
from protected projects.

## Options

1. Extract code from the protected project with `git archive` and sanitize it.
2. Build on a fresh Laravel skeleton and re-implement the patterns from their
   design description.

## Decision

Option 2. The template starts from `laravel/laravel` and official packages.
Patterns are re-written from documented designs; no file, identifier, domain,
host name or data comes from a protected project.

## Consequences

- No risk of leaking production identifiers, secrets or history.
- More initial work than extraction.
- The template is free of the protected application's business vocabulary.

## Security implications

Eliminates the sanitization step and its failure modes.

## Performance implications

None.

## Reversal or migration strategy

Not reversible by design; any later reuse of protected code needs a separate
approval and its own review.
