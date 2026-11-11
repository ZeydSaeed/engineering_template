# ADR-0019: Queues by workload and a transactional outbox

- Status: Accepted
- Date: 2026-11-12

## Context

Slow work (antivirus scans, PDFs, imports) must not delay notifications or
ordinary jobs. Events that other parts of the system rely on must not be lost
when a process dies between a commit and a dispatch, and must not escape for
a change that rolled back.

## Problem

Organise background work so it is isolated, bounded, observable, and
correct under retries and crashes.

## Options

1. One queue, framework defaults (`after_commit` off, one `retry_after`).
2. Queues by workload with limits from one registry; jobs held until commit;
   an outbox table for events that must not be lost; idempotent consumers.
3. A message broker (RabbitMQ, Kafka).

## Decision

Option 2. Option 3 adds infrastructure without a measured need.

- `config/platform.php` `queues`: `default`, `notifications`, `outbox`,
  `files`, `pdf`, `imports`, `exports`, each with timeout, attempts, backoff,
  and whether it is long. Every job extends `PlatformJob` and names its
  queue; nothing else sets these values.
- `after_commit` on the database and Redis connections: a job dispatched in a
  transaction is queued on commit, or not at all.
- `retry_after` per connection exceeds the longest timeout on it; imports and
  exports use `database_long` / `redis_long` (960 s). Tested.
- Outbox: `Outbox::record()` only inside the change's transaction;
  `OutboxRelay` claims due rows with `FOR UPDATE SKIP LOCKED`, pushes the job
  before its own commit (a deferred push could lose a message already marked
  as sent), uses a savepoint per message, and retries with backoff until
  `max_attempts`, then marks the message dead and logs it. Delivery is at
  least once.
- `OutboxJob` records `(message uuid, consumer)` in `processed_messages` in
  the same transaction as its own work, so a second delivery does nothing.
  Effects outside the database must be idempotent themselves (send the
  message uuid as an idempotency key).
- Final job failures and queue backlogs are logged without payloads or
  exception messages; `queue:monitor` runs every minute.
- Workers: one systemd unit per queue (`deploy/systemd`), memory and CPU
  limits, graceful stop longer than the longest job.

## Consequences

Measured locally on PostgreSQL with real processes
(tests/Concurrency/RaceConditionsTest.php): four relays on 300 messages
dispatched each once; with 50 duplicate deliveries, each message took effect
once.

## Security implications

Payloads carry identifiers, not personal data; logs never carry payloads.

## Performance implications

A relay pass is one indexed query per batch. Production runs the relay as a
service (`platform:outbox-relay --loop`); the scheduler's minute pass is the
fallback.

## Reversal or migration strategy

A broker can replace the relay's target later; producers and consumers keep
their interfaces.
