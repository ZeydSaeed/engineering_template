<?php

namespace App\Platform\Access\Contracts;

use BackedEnum;

/**
 * Implemented by the user model through the HasRoles trait (ADR-0014).
 */
interface HoldsRoles
{
    /**
     * @return list<GrantsPermissions>
     */
    public function roles(): array;

    public function hasRole(GrantsPermissions $role): bool;

    public function hasPermission(BackedEnum|string $permission): bool;

    public function flushRoles(): void;
}
