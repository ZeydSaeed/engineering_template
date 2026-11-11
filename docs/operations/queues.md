# Queues and background work

Status: implemented and tested as of Phase 4 (ADR-0019).

## Lifecycle

```
Request → Form Request → Action
  └ BEGIN → lock → rules → write → audit → Outbox::record() (optional) → COMMIT
  └ jobs dispatched in the transaction are queued at COMMIT (after_commit)
Outbox relay (service, and every minute from the scheduler)
  └ FOR UPDATE SKIP LOCKED → push job → mark dispatched → COMMIT
Worker for the queue
  └ OutboxJob: record processed message + do the work in one transaction
  └ failure → retry with backoff → after the last attempt: failed_jobs + log
Monitoring: queue:monitor (backlog), final failures, dead outbox messages
```

## Queues

| Queue         | Timeout | Attempts | Backoff (s)      | Connection |
| ------------- | ------- | -------- | ---------------- | ---------- |
| default       | 60 s    | 3        | 10, 60, 300      | default    |
| notifications | 30 s    | 5        | 10, 60, 300, 900 | default    |
| outbox        | 60 s    | 5        | 10, 60, 300, 900 | default    |
| files         | 120 s   | 3        | 30, 120, 600     | default    |
| pdf           | 120 s   | 2        | 30               | default    |
| imports       | 900 s   | 1        | none             | *_long     |
| exports       | 900 s   | 2        | 120              | *_long     |

Change a value in `config/platform.php`; `QueuesTest` checks that every
connection's `retry_after` still exceeds its longest timeout.

## Writing a job

```php
class GenerateCertificate extends PlatformJob
{
    public const QUEUE = 'pdf';

    public function __construct(public readonly int $certificateId)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        // Idempotent: check whether the PDF already exists before building it.
    }
}
```

An event other modules must not miss: record it in the outbox inside the
transaction, register its topic in `platform.outbox.handlers`, and handle it
in a class extending `OutboxJob`.

## Running workers (Linux)

`deploy/systemd/queue-worker@.service` (one instance per queue) and
`deploy/systemd/outbox-relay.service`. Start with one worker per queue, then
size from measurements (docs/operations/capacity.md): CPU-bound queues (pdf)
no more workers than cores; I/O-bound queues (notifications) more.

After each release: `php artisan queue:restart` (workers finish their job and
exit; systemd starts them on the new code).

## Failures

- `failed_jobs` keeps final failures 30 days (`queue:prune-failed`).
- Retry one after fixing the cause: `php artisan queue:retry <uuid>`.
- Dead outbox messages: `select * from outbox_messages where status = 'dead'`;
  after fixing the cause set `status = 'pending', attempts = 0,
  available_at = now()` for the rows to send again (consumers are
  idempotent).

## Local development

The database queue (`QUEUE_CONNECTION=database`); `composer dev` runs a
listener. Redis is exercised in CI (`RedisQueueTest`).
