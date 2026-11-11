# ADR-0018: Caching only with an owner, a staleness limit and a data class

- Status: Accepted
- Date: 2026-11-11

## Context

Caches that nobody invalidates serve wrong answers; caches of personal data
in shared layers leak it. The template caches nothing at the application
level yet, and the first measurements show no query worth caching (the
slowest page runs one statement of the page's own).

## Problem

Decide when and how a module may cache.

## Options

1. Cache freely where it seems to help.
2. A short policy every cache must satisfy, written in the code that adds it.

## Decision

Option 2. See docs/standards/caching.md. In short: each cache names what it
holds, why (a measured cost), its time to live, who invalidates it, what
happens when the cache store is down, and its data class. Keys carry a
version. Writes and authorisation decisions never read from a cache.
Personal data never goes into a shared (CDN or HTTP) cache. Signed-in
responses are `no-store` (ADR-0016).

## Consequences

Caches appear only where a measurement justifies them.

## Security implications

No cross-user leaks through shared caches; stale permissions cannot grant
access.

## Performance implications

Fewer, deliberate caches; cache stampedes handled with locks or
stale-while-revalidate where a cache exists.

## Reversal or migration strategy

Not applicable: the policy applies to each new cache.
