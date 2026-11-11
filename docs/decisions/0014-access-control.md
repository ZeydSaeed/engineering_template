# ADR-0014: Roles and permissions as code

- Status: Accepted
- Date: 2026-11-11

## Context

Permissions stored as editable data drift and are rarely reviewed.
Applications built on the template need a small, reviewable set of roles.

## Problem

Define who may do what in a way that is reviewed, tested and safe against
privilege escalation.

## Options

1. Roles and permissions in database tables, edited at runtime.
2. Enums in code: each role lists its permissions explicitly; only the
   assignment of roles to users is data.

## Decision

Option 2.

- The application owns `App\Access\Role` (implements `GrantsPermissions`)
  and `App\Access\Permission`; `config/platform.php` names them.
- Every permission is a Gate ability of the same name.
- Roles list permissions explicitly, never `Permission::cases()`;
  `PermissionMatrixTest` holds the full matrix by hand.
- `AssignRole` / `RevokeRole` are the only writers: nobody changes their own
  roles; the actor's permission is re-checked under the row locks; the last
  administrator cannot be removed (all administrator assignments are locked
  first, so concurrent removals serialise); every change is audited.

## Consequences

A new permission reaches nobody until a role lists it in a reviewed commit.

## Security implications

Prevents self-escalation and administrator lock-out; defence in depth with
Policies and Form Requests in modules.

## Performance implications

Roles load once per user model instance.

## Reversal or migration strategy

Per-user exceptions (grant or deny one permission) can be added later as a
separate table without changing the enums.
