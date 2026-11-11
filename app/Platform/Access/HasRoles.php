<?php

namespace App\Platform\Access;

use App\Platform\Access\Contracts\GrantsPermissions;
use BackedEnum;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Roles and permissions for the user model (ADR-0014). Roles are loaded once
 * per model instance; call flushRoles() after grants change.
 */
trait HasRoles
{
    /** @var list<GrantsPermissions>|null */
    private ?array $resolvedRoles = null;

    /**
     * @return HasMany<RoleAssignment, $this>
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    /**
     * @return list<GrantsPermissions>
     */
    public function roles(): array
    {
        if ($this->resolvedRoles !== null) {
            return $this->resolvedRoles;
        }

        $roles = [];

        foreach ($this->roleAssignments as $assignment) {
            $role = AccessCatalog::role($assignment->role);

            if ($role !== null) {
                $roles[] = $role;
            }
        }

        return $this->resolvedRoles = $roles;
    }

    public function hasRole(GrantsPermissions $role): bool
    {
        return in_array($role, $this->roles(), true);
    }

    public function hasPermission(BackedEnum|string $permission): bool
    {
        $value = $permission instanceof BackedEnum ? $permission->value : $permission;

        foreach ($this->roles() as $role) {
            foreach ($role->permissions() as $granted) {
                if ($granted->value === $value) {
                    return true;
                }
            }
        }

        return false;
    }

    public function flushRoles(): void
    {
        $this->resolvedRoles = null;
        $this->unsetRelation('roleAssignments');
    }
}
