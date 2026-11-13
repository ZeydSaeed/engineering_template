# Runbook: the Requests module

Alerts from the module's own metrics (`module_requests_*`, ADR-0033). Each
section: what the alert means, what to check, what to do, and how to know
it is fixed.

## RequestsWaitingLong

- **Meaning:** the oldest submitted request has waited over three days for a decision. This is a business signal, not a fault: people are waiting.
- **Check:** `module_requests_by_status{status="submitted"}` (how many wait); whether anyone holds the `approver` role (`/admin/users?filter[role]=approver`); whether approvers were told: `platform_outbox_messages{status="dead"}` and the `outbox` queue (a dead `requests.submitted` message means approvers were never notified).
- **Act:** if nobody has the role, an administrator assigns it; if notifications failed, follow OutboxMessagesDead in docs/runbooks/application.md; otherwise tell the approvers' manager which requests wait (`/requests?filter[scope]=to_decide&sort=submitted_at&direction=asc`, oldest first).
- **Verify:** `module_requests_oldest_waiting_age_seconds` falls below three days once the oldest request is decided.
