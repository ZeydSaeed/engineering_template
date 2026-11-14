# Security standards

Status: implemented and tested as of Phase 9 (checked in the Phase 10
readiness review), except where marked Planned.

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
| Files         | Allow-listed extension and detected type, size limit, random stored name, scanned before served (fail closed), served only to who may see the owner, `attachment` with a sandbox CSP                      | `FileStore` (ADR-0020)            |
| Supply chain  | Composer and npm audits, dependency review, pinned CI actions, Gitleaks, Semgrep; release built twice and compared, SBOM, signed attestation                                                              | CI (ADR-0030, ADR-0031)           |
| Testing       | ZAP baseline (passive, signed out) against a release deployed with the production templates on every push                                                                                                 | `security.yml` (ADR-0030)         |
| Monitoring    | Alerts on a broken or unverified audit chain and on missing or failed backups, each with a runbook                                                                                                        | `alerts.yml` (ADR-0023)           |

Never log or audit: passwords, tokens, one-time codes, recovery codes, file
contents, full personal identifiers.

Planned: audit records for passkey changes (two-factor changes are
audited); an authenticated, active DAST scan on a staging host (the CI scan
is passive and signed out); removing `'unsafe-inline'` from `style-src`
(accepted residual risk, ADR-0016).
