<?php

namespace App\Access;

use App\Platform\Access\Contracts\GrantsPermissions;

/**
 * Roles of this application. Permissions are listed explicitly, never with
 * Permission::cases(), so a new permission reaches nobody until it is added
 * here and to the permission matrix test (ADR-0014).
 */
enum Role: string implements GrantsPermissions
{
    case Administrator = 'administrator';
    case Auditor = 'auditor';
    case Approver = 'approver';

    public function permissions(): array
    {
        return match ($this) {
            // Administrators see requests; deciding is the approvers' job.
            self::Administrator => [Permission::RolesManage, Permission::AuditView, Permission::RequestsViewAll],
            self::Auditor => [Permission::AuditView],
            self::Approver => [Permission::RequestsViewAll, Permission::RequestsDecide],
        };
    }
}
