<?php

namespace App\Access;

/**
 * Permissions of this application. Each case is a Gate ability of the same
 * name (`can:roles.manage`). Business modules add their own cases here, and
 * every grant is written down in tests/Feature/Access/PermissionMatrixTest.php.
 */
enum Permission: string
{
    case RolesManage = 'roles.manage';
    case AuditView = 'audit.view';

    // Requests module (ADR-0033): see everyone's requests; decide on them.
    case RequestsViewAll = 'requests.view_all';
    case RequestsDecide = 'requests.decide';
}
