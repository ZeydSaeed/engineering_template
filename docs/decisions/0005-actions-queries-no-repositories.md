# ADR-0005: Actions for writes, queries for reads, no repository layer

- Status: Accepted
- Date: 2026-11-11

## Context

Laravel's Eloquent is already a data-access layer. Business operations need a
home that owns transactions, locks, audit and events.

## Problem

Decide where use cases, reads and persistence live.

## Options

1. Logic in controllers and models.
2. Repositories over Eloquent for every model.
3. Actions (one class per write use case) and query classes for non-trivial
   reads, with Eloquent used directly inside them.

## Decision

Option 3.

- Action: one write use case. It opens the transaction
  (`DB::transaction(fn, attempts)`), locks the aggregate root, re-checks state
  under the lock, writes, records audit, and dispatches after-commit effects.
- Query: a read with filters, joins or projections; returns DTOs or resources.
  Simple lookups stay inline.
- Controller: validate (Form Request), authorize (Policy), call one Action or
  Query, return a response.
- A repository is added only with an ADR showing its value (for example a
  second storage backend).

## Consequences

Business rules have one home; controllers and models stay small.

## Security implications

Actions re-check authorization for non-HTTP callers (jobs, console, APIs).

## Performance implications

Queries are the unit measured with `EXPLAIN` and query-count tests.

## Reversal or migration strategy

A repository can wrap a query or action later without changing callers.
