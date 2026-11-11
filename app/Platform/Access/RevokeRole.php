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
 * Removes a role (ADR-0014). Nobody changes their own roles, and the last
 * administrator can never be removed: every administrator assignment is
 * locked first, so two administrators removing each other at the same time
 * cannot both succeed.
 */
class RevokeRole
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Model&HoldsRoles&Authenticatable $actor, Model&HoldsRoles $target, GrantsPermissions $role): void
    {
        if ($actor->is($target)) {
            throw new DomainRuleViolation(__('You cannot change your own roles.'), 'role');
        }

        DB::transaction(function () use ($actor, $target, $role): void {
            $target->newQuery()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            $administrators = RoleAssignment::query()
                ->where('role', AccessCatalog::administrator()->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('user_id')
                ->all();

            // Re-checked under the locks: the actor may have just lost the role.
            $actor->flushRoles();

            if (! $actor->hasPermission(AccessCatalog::managePermission())) {
                throw new AuthorizationException;
            }

            $removingAdministrator = $role === AccessCatalog::administrator()
                && in_array($target->getKey(), $administrators, false);

            if ($removingAdministrator && count($administrators) <= 1) {
                throw new DomainRuleViolation(__('The last administrator cannot be removed.'), 'role');
            }

            $deleted = RoleAssignment::query()
                ->where('user_id', $target->getKey())
                ->where('role', $role->value)
                ->delete();

            if ($deleted > 0) {
                $this->audit->record('access.role_revoked', $target, ['role' => (string) $role->value], actor: $actor);
            }
        }, 3);

        $target->flushRoles();
    }
}
