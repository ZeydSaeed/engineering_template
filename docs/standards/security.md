# Security standards

Status: implemented and tested as of Phase 2, except where marked Planned.

| Area          | Rule                                                                                                                                                                                                      | Where                             |
| ------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------- |
| Configuration | Production and staging refuse to boot with debug on, no key, insecure or shared session cookies, unencrypted sessions, plain-HTTP URL, HSTS or CSP off, a missing or weak audit key, or a denied database | `EnvironmentValidator` (ADR-0010) |
| Headers       | nosniff, frame DENY, referrer policy, permissions policy, COOP, HSTS, nonce CSP, no-store for signed-in users                                                                                             | `SecurityHeaders` (ADR-0016)      |
| Sign-in       | Second factor required; registration off; strong passwords everywhere; per-address and per-IP limits                                                                                                      | ADR-0015                          |
| Sessions      | Database driver, encrypted, `__Host-` cookie over HTTPS, SameSite=Lax                                                                                                                                     | `config/session.php`              |
| Authorization | Enum roles and permissions, one Gate per permission, permission matrix test, no self-change, last administrator protected                                                                                 | ADR-0014                          |
| Audit         | Append-only, hash-chained, keyed; secrets masked; identifiers of failed sign-ins hashed                                                                                                                   | ADR-0013                          |
| Logs          | JSON lines with request id; secret keys masked at any depth                                                                                                                                               | ADR-0009                          |
| Database      | Runtime role cannot run DDL or change the audit trail                                                                                                                                                     | ADR-0012                          |
| Secrets       | Never in git (`.env*`, `.tools`, `.env.db-roles` ignored); `APP_KEY` and `AUDIT_HMAC_KEY` separate                                                                                                        | `.gitignore`                      |
| Supply chain  | Composer and npm audits; pinned CI actions                                                                                                                                                                | CI (more in Phase 8)              |

Never log or audit: passwords, tokens, one-time codes, recovery codes, file
contents, full personal identifiers.

Planned: file upload security (Phase 5), SAST/DAST and SBOM (Phase 8),
security monitoring and alerts (Phase 6), audit of passkey changes.
