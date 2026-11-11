<?php

namespace App\Platform\Access;

use App\Platform\Access\Contracts\GrantsPermissions;
use App\Platform\Access\Contracts\HoldsRoles;
use App\Platform\Audit\AuditLogger;
use App\Platform\Exceptions\DomainRuleViolation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Grants a role (ADR-0014). Idempotent: granting a held role changes nothing
 * and records nothing.
 */
class AssignRole
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Model&HoldsRoles&Authenticatable $actor, Model&HoldsRoles $target, GrantsPermissions $role): void
    {
        if ($actor->is($target)) {
            throw new DomainRuleViolation(__('You cannot change your own roles.'), 'role');
        }

        DB::transaction(function () use ($actor, $target, $role): void {
            // The target's row first, as in RevokeRole, so the two never
            // wait on each other in opposite orders.
            $target->newQuery()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            $actor->flushRoles();

            if (! $actor->hasPermission(AccessCatalog::managePermission())) {
                throw new AuthorizationException;
            }

            $exists = RoleAssignment::query()
                ->where('user_id', $target->getKey())
                ->where('role', $role->value)
                ->exists();

            if ($exists) {
                return;
            }

            $assignment = new RoleAssignment;
            $assignment->forceFill([
                'user_id' => $target->getKey(),
                'role' => $role->value,
                'granted_by' => $actor->getKey(),
            ])->save();

            $this->audit->record('access.role_assigned', $target, ['role' => (string) $role->value], actor: $actor);
        }, 3);

        $target->flushRoles();
    }
}
