# ADR-0016: Security headers and Content Security Policy

- Status: Accepted
- Date: 2026-11-11

## Context

Browsers enforce many protections only when asked through response headers.

## Problem

Send a strict, testable baseline on every response.

## Options

1. Headers in the web server configuration.
2. A global application middleware, so every environment (and every test)
   sends the same headers, error pages included.

## Decision

Option 2 (`SecurityHeaders`, global, outermost after the request id):
`X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
`Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`
denying camera, microphone, location, payment and USB,
`Cross-Origin-Opener-Policy: same-origin`, HSTS over HTTPS when enabled
(`PLATFORM_HSTS`, required in production), `Cache-Control: no-store, private`
for signed-in users, and a CSP:

- scripts: same origin plus a per-request nonce (Vite adds it to its tags);
- `object-src 'none'`, `frame-ancestors 'none'`, `base-uri 'self'`,
  `form-action 'self'`;
- styles allow `'unsafe-inline'`: Radix UI and toasts set inline styles at
  runtime. Accepted risk: CSS-based exfiltration needs an HTML injection
  point first, and scripts stay nonce-only;
- the Vite dev server is allowed only in the local environment.

A response that sets its own CSP or frame policy keeps it.

## Consequences

Inline scripts without the nonce stop working; third-party scripts must be
added deliberately in `config/platform.php`.

## Security implications

Strong XSS and clickjacking mitigation.

## Performance implications

Negligible.

## Reversal or migration strategy

Each source list is configuration.
