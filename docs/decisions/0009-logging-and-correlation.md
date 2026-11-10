# ADR-0009: Structured logging and request correlation

- Status: Accepted
- Date: 2026-11-11

## Context

Logs, audit records and jobs from one request must be linkable, and logs must
be searchable by field.

## Problem

Choose a log format and a correlation mechanism.

## Options

1. Laravel's default line format.
2. JSON lines with a request id carried in Laravel `Context`.

## Decision

Option 2.

- A global middleware sets `request_id` (an inbound `X-Request-Id` is reused
  only when it matches `^[A-Za-z0-9._-]{8,64}$`, otherwise a UUIDv7), adds it
  to `Context` and returns it as `X-Request-Id`. `Context` carries it into
  queued jobs.
- Production logs are JSON lines on stderr or a daily file with a fixed
  retention.
- Never logged: passwords, tokens, secrets, one-time codes, file contents,
  full personal identifiers. A processor masks known sensitive keys.

## Consequences

One id ties a user report to logs, audit rows and jobs.

## Security implications

Masking reduces the impact of log exposure.

## Performance implications

JSON formatting cost is negligible at expected volumes.

## Reversal or migration strategy

The formatter is a logging channel setting.
