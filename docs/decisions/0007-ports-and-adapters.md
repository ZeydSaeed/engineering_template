# ADR-0007: Ports and adapters for external services only

- Status: Accepted
- Date: 2026-11-11

## Context

External services (antivirus, object storage, mail, SMS, identity proxies,
other systems' APIs) fail, change and need fakes in tests.

## Problem

Isolate them without wrapping every internal class in an interface.

## Options

1. Call SDKs and HTTP clients directly.
2. An interface for every service.
3. An interface (port) for each external service, implemented by adapters;
   internal services stay concrete.

## Decision

Option 3. Each port has a contract test run against every adapter and a fake.
Adapters set connect and total timeouts, bounded retries with backoff, and
fail closed where safety depends on the result (for example a file stays
unverified when the scanner is down).

## Consequences

External behaviour is swappable and testable; internal code stays simple.

## Security implications

Outbound calls go through one place where allowed hosts, timeouts and secret
handling are enforced (SSRF protection).

## Performance implications

Timeouts bound the cost of a slow dependency.

## Reversal or migration strategy

An adapter is replaced by binding another implementation.
