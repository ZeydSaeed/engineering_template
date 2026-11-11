<?php

namespace App\Platform\Access\Contracts;

use BackedEnum;

/**
 * Implemented by the application's role enum.
 */
interface GrantsPermissions extends BackedEnum
{
    /**
     * @return list<BackedEnum>
     */
    public function permissions(): array;
}
