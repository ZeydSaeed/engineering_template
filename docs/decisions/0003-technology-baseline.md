# ADR-0003: Technology baseline and version policy

- Status: Accepted
- Date: 2026-11-11

## Context

Support windows checked on 2026-11-11 (public sources):

| Component                        | Support                                                 |
| -------------------------------- | ------------------------------------------------------- |
| PHP 8.4                          | active until 2026-12-31, security until 2028-12-31      |
| PHP 8.5                          | active until 2027-12-31, security until 2029-12-31      |
| Laravel 13 (released 2026-03-17) | bug fixes until Q3 2027, security until Q1 2028; no LTS |
| PostgreSQL 18                    | supported until 2030-11                                 |
| Inertia 3                        | requires React 19; Laravel adapter requires Laravel 11+ |

## Problem

Pick versions that stay supported for the template's first years without
chasing novelty.

## Options

1. PHP 8.4, the version already installed locally.
2. PHP 8.5 with a fallback to 8.4 when a dependency is incompatible.

## Decision

- PHP 8.5 (installed beside 8.4 in Laravel Herd, used for this workspace only).
- Laravel 13; upgrade to the next major after it has had its first bug-fix
  releases, through a planned, tested upgrade.
- PostgreSQL 18 for development, CI and production.
- Redis for cache, queue and locks; the choice between Redis and Valkey is a
  later ADR. Locally the database drivers are used until one is installed.
- React 19, Inertia 3, TypeScript strict, Tailwind CSS 4, and the starter
  kit's Vite+ toolchain with Node 24 LTS (ADR-0011 amends this line).
- Pest 5, Larastan (level 8), Pint, deptrac, oxlint/oxfmt through Vite+,
  Playwright, k6.

CI runs the same PostgreSQL major version as production.

## Consequences

- One framework major upgrade per year is planned work.
- Any dependency that fails on PHP 8.5 is recorded; if it cannot be replaced,
  this ADR is superseded with PHP 8.4.

## Security implications

Supported versions receive security fixes for the planning horizon.

## Performance implications

None measured; version changes are benchmarked in the performance phase.

## Reversal or migration strategy

Versions are pinned in `composer.json`, `package.json`, the CI matrix and the
deployment templates; changing one is a reviewed change with a CI run.
