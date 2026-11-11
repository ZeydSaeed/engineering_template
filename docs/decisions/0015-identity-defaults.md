# ADR-0015: Identity defaults

- Status: Accepted
- Date: 2026-11-11

## Context

The starter kit ships public registration, optional two-factor and password
rules that apply only in production.

## Problem

Make the secure choice the default for applications with staff accounts.

## Options

1. Keep the kit's defaults.
2. Secure defaults that an application can relax explicitly.

## Decision

Option 2.

- Second factor required: until a user has a confirmed authenticator app or
  a passkey, only enrolment, password confirmation, e-mail verification and
  sign-out are open (`RequireSecondFactor`). An unverified address is handled
  first. `PLATFORM_REQUIRE_SECOND_FACTOR=false` relaxes it.
- No public registration: the page, action, test and links are removed, as
  the kit's installer does when registration is declined. It is a build-time
  choice, not a setting: the frontend's route helpers are generated from the
  registered routes, so a page for a missing route breaks the build. An
  application that needs registration adds `Features::registration()`, its
  page and its tests in a reviewed change.
- Password rules everywhere: 12 to 128 characters, mixed case, a number and a
  symbol; the breach check (an outside call) in production only.
- Sessions: database driver, encrypted, `__Host-` cookie over HTTPS (secure,
  host-only, path "/"), SameSite=Lax.
- Sign-in limits: per address and IP per minute, and per IP per hour across
  addresses; two-factor attempts per minute.
- `Model::shouldBeStrict()` outside production.
- One portal (one guard) by default. Several portals on separate hosts with
  separate guards and sessions are a documented pattern, added when an
  application needs them (YAGNI).

## Consequences

Kit tests that sign in now use users with a second factor and strong
passwords.

## Security implications

Phishing-resistant passkeys or TOTP for every account; no anonymous account
creation; stolen session cookies cannot be replayed over HTTP or on another
subdomain.

## Performance implications

One indexed query per request for users without a confirmed authenticator
app (passkey lookup).

## Reversal or migration strategy

Each default is one setting.
