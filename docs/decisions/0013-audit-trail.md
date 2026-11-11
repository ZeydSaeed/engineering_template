# ADR-0013: Append-only, hash-chained audit trail

- Status: Accepted
- Date: 2026-11-11

## Context

Applications built on the template handle decisions that must be provable
later (who did what, when, from where). A table the application can update
proves nothing.

## Problem

Record security-relevant actions so that changes, deletions and reordering
are prevented where possible and detected otherwise.

## Options

1. A plain table written by the application.
2. Append-only table: trigger plus revoked rights.
3. Option 2 plus a keyed hash chain verifiable outside the database.

## Decision

Option 3.

- `audit_events`: time (timestamptz, microseconds), actor, guard, action,
  subject (morph class and key), request id, IP, user agent, `changes`
  (before/after of allow-listed fields) and `metadata` (jsonb).
- PostgreSQL trigger refuses UPDATE, DELETE and TRUNCATE; the runtime role
  loses those rights (ADR-0012). The model refuses them too.
- Each record stores `previous_hash` and
  `hash = HMAC-SHA256(AUDIT_HMAC_KEY, previous_hash + canonical record)`.
  Writers are serialised with a transaction-scoped advisory lock so the chain
  has one order. `php artisan platform:audit-verify` recomputes it.
- `AUDIT_HMAC_KEY` is separate from `APP_KEY`, at least 32 characters, and
  required in production (ADR-0010).
- Masked keys (passwords, tokens, codes) are replaced before writing.
  Failed sign-ins store only a keyed hash of the typed identifier.

## Consequences

- Tampering by someone who can bypass the trigger (the table owner) is
  detected unless they also hold the key.
- Audit writes are serialised; at the expected volume (tens of writes per
  second at most) this is negligible, and it is measured in Phase 3.
- Passkey registration and removal raise no framework event yet and are not
  audited (open item).

## Security implications

Provides non-repudiation evidence; the key must be backed up separately from
the database (Phase 6).

## Performance implications

One advisory lock and one indexed lookup of the latest hash per record.

## Reversal or migration strategy

The chain can be anchored and restarted (new key, first record with a null
previous hash) with a documented, audited procedure.
