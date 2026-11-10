# ADR-0010: Configuration and environment validation

- Status: Accepted
- Date: 2026-11-11

## Context

A misconfigured production (debug on, empty key, insecure cookies) is a common
and silent failure.

## Problem

Make unsafe configuration impossible to run unnoticed.

## Options

1. Document required settings.
2. Validate the configuration at boot outside local and testing, and fail
   closed with a clear message.

## Decision

Option 2. `config/platform.php` holds cross-cutting settings; each module has
its own config file. In `production` and `staging` an environment validator
refuses to boot when:

- `APP_DEBUG` is true;
- `APP_KEY` is empty;
- secure session cookies are off;
- HSTS is off;
- `APP_URL` is not HTTPS;
- the database name is on the deny list (`platform.database.denied_names`).

`env()` is read only in `config/` (ADR-0004), so `config:cache` is always safe.

## Consequences

A bad deploy fails at once, before serving traffic.

## Security implications

Prevents the most common insecure production settings.

## Performance implications

Runs once per worker boot; with cached config the cost is negligible.

## Reversal or migration strategy

Rules are a list in the validator; each rule can be disabled only by a
reviewed change.
