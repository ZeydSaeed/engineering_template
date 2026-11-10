# ADR-0008: Error handling

- Status: Accepted
- Date: 2026-11-11

## Context

Users need clear messages for broken business rules; operators need full
detail for unexpected failures; attackers should learn nothing.

## Problem

Separate expected rule violations from faults, and render both safely.

## Options

1. Throw generic exceptions and let the framework render them.
2. A single `DomainRuleViolation` exception for expected rule breaks, rendered
   as a validation-style error; everything else is a fault, reported and shown
   as a generic page with the request id.

## Decision

Option 2.

- `DomainRuleViolation(field, message, status, context)`: not reported to
  error tracking; rendered as a 422-style error (JSON for API clients,
  session errors for Inertia).
- Faults: reported with request id and user id, never with secrets; the
  response shows a generic message and the request id.
- `APP_DEBUG` must be false outside local; the environment validator refuses
  to boot otherwise (ADR-0010).

## Consequences

Business errors are part of the API contract and tested.

## Security implications

No stack traces or internals in responses outside local.

## Performance implications

None.

## Reversal or migration strategy

Additional exception types can be added beside it.
