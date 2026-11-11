<?php

namespace App\Platform\Access;

use App\Platform\Access\Contracts\GrantsPermissions;
use BackedEnum;
use LogicException;

/**
 * The application's role and permission enums, as named in
 * config/platform.php (ADR-0014). Static because it only reads
 * configuration, so models can use it without the container.
 */
final class AccessCatalog
{
    /**
     * @return class-string<GrantsPermissions>
     */
    public static function roleEnum(): string
    {
        $class = (string) config('platform.access.roles');

        if (! is_subclass_of($class, GrantsPermissions::class)) {
            throw new LogicException("platform.access.roles [{$class}] must be an enum implementing GrantsPermissions.");
        }

        return $class;
    }

    /**
     * @return class-string<BackedEnum>
     */
    public static function permissionEnum(): string
    {
        $class = (string) config('platform.access.permissions');

        if (! is_subclass_of($class, BackedEnum::class)) {
            throw new LogicException("platform.access.permissions [{$class}] must be a backed enum.");
        }

        return $class;
    }

    /**
     * @return list<BackedEnum>
     */
    public static function permissions(): array
    {
        return self::permissionEnum()::cases();
    }

    public static function role(string $value): ?GrantsPermissions
    {
        $role = self::roleEnum()::tryFrom($value);

        return $role instanceof GrantsPermissions ? $role : null;
    }

    public static function administrator(): GrantsPermissions
    {
        return self::role((string) config('platform.access.administrator_role'))
            ?? throw new LogicException('platform.access.administrator_role names no role.');
    }

    public static function managePermission(): string
    {
        return (string) config('platform.access.manage_permission');
    }
}
