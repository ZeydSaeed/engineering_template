# ADR-0006: Selective CQRS

- Status: Accepted
- Date: 2026-11-11

## Context

Some future applications have a write path that must be strictly consistent
(for example grade entry and approval) and a read path with sudden high volume
(for example result publication).

## Problem

Serve heavy reads without weakening the write model.

## Options

1. One model for reads and writes everywhere.
2. Full CQRS with event sourcing and eventually consistent projections.
3. Separate commands and queries in code (ADR-0005); dedicated read
   projections only where a measured read load needs them.

## Decision

Option 3. A projection is a plain table built inside the same transaction that
publishes it, or rebuilt and switched atomically, so readers never see a mix of
versions. No event sourcing, no message broker.

## Consequences

Projections are added per module with an ADR naming the load that justifies
them and their rebuild procedure.

## Security implications

Projections hold only the fields readers may see.

## Performance implications

Reads hit a narrow, indexed table and can be cached by version.

## Reversal or migration strategy

A projection can be dropped and reads pointed back at the write model.
