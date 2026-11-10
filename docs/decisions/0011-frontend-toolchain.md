# ADR-0011: Keep the starter kit's Vite+ toolchain; Node 24 LTS

- Status: Accepted (amends the "official Vite" line of ADR-0003)
- Date: 2026-11-11

## Context

ADR-0003 chose "the official Vite". The official Laravel React starter kit
(`laravel/react-starter-kit`, main at 012f4248) ships `vite-plus` 1.4.0
instead: Vite's core published as `@voidzero-dev/vite-plus-core` plus a `vp`
command that builds, formats (oxfmt) and lints (oxlint, type-aware). It
requires Node `^22.18 || ^24.11 || >=26`. The workstation has Node 22.13 on
PATH and 25.6 in Herd's nvm, neither of which qualifies.

## Problem

Follow the upstream kit or replace its toolchain; and provide a supported
Node version without changing the machine for other projects.

## Options

1. Replace vite-plus with plain Vite, ESLint and Prettier.
2. Keep the kit's vite-plus toolchain, so upgrades from upstream stay simple.

## Decision

Option 2, with Node 24 LTS (v24.22.2, SHA-256 checked against nodejs.org) kept
portable in `.tools/node` (not committed) and put on PATH per shell by
`scripts/env.ps1`. CI uses Node 24.

Frontend module boundaries are checked by the PHP architecture suite
(`tests/Support/ModuleDependencies.php`), which reads imports directly and
does not depend on the linter.

## Consequences

- Less divergence from the upstream kit; its upgrades apply cleanly.
- The toolchain is newer than plain Vite; if it causes problems, Option 1 is
  a contained change (package.json scripts, vite.config.ts lint/fmt blocks).

## Security implications

The toolchain runs at build time only. Dependency audits cover it.

## Performance implications

Rolldown-based builds; not benchmarked here.

## Reversal or migration strategy

Swap the `vite` override back to `vite`, add ESLint and Prettier, and move the
lint and format settings into their own configs.
