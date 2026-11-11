# ADR-0021: Web server templates, nested timeouts, guarded outbound calls

- Status: Accepted
- Date: 2026-11-12

## Context

When an inner layer outlives an outer one, the outer layer answers 504
while work continues, and retries pile up. Outbound calls built from
request data invite SSRF; a slow dependency without limits ties up workers.

## Problem

Make the request path's limits consistent and every outbound call bounded.

## Options

1. Defaults of each tool, configured per server by hand.
2. Templates in the repository with limits a test keeps consistent, and one
   outbound HTTP client with declared services.

## Decision

Option 2.

- `deploy/nginx/app.conf`, `deploy/php/app-pool.conf`, `deploy/php/app-php.ini`:
  unknown hosts dropped (444, TLS handshake refused), HTTP/2, TLS 1.2/1.3,
  only `index.php` executes, dotfiles denied, probes local only, hashed assets
  cached for a year, a per-IP request limit, a pool per application with its
  own user, socket, `open_basedir`, disabled process functions, slow log and
  status page.
- Timeouts strictly nested, each shorter than the one before: edge 100 s,
  Nginx 90 s, PHP-FPM 75 s, PHP 60 s, then outbound calls (timeout times
  attempts). Upload limits nested the same way: Nginx 12 MB, `post_max_size`
  11 MB, `upload_max_filesize` 10 MB, the application 10 MB.
  `DeployTemplatesTest` enforces both chains.
- `OutboundHttp`: services declared with an https base URL; paths must be
  relative (no request-controlled hosts); connect and total timeouts;
  retries with exponential backoff only for idempotent methods on connection
  errors and 5xx; redirects not followed; the request id forwarded; a circuit
  breaker opens after repeated failures for a cool-down. Client errors (4xx)
  do not count against the service.

## Consequences

A new outbound integration is a config entry plus an adapter (ADR-0007).

## Security implications

No SSRF through outbound calls; less exposed surface on the web server.

## Performance implications

Bounded worst case per request; a failing dependency costs one fast
refusal while the circuit is open.

## Reversal or migration strategy

Template values are plain configuration; the test shows any inconsistency.
