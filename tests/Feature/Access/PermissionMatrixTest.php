<?php

use App\Access\Permission;
use App\Access\Role;

/*
| Every grant, written by hand (ADR-0014). Changing a role's permissions
| fails this test until the matrix below is changed too, in the same
| reviewed commit.
*/

it('grants exactly the permissions written here', function () {
    $matrix = [
        'administrator' => ['roles.manage', 'audit.view'],
        'auditor' => ['audit.view'],
    ];

    $actual = [];

    foreach (Role::cases() as $role) {
        $actual[$role->value] = array_map(fn (Permission $permission): string => $permission->value, $role->permissions());
    }

    expect($actual)->toBe($matrix);
});

it('lists every permission under some role', function () {
    $granted = collect(Role::cases())->flatMap(fn (Role $role) => $role->permissions())->unique()->values();

    expect($granted)->toHaveCount(count(Permission::cases()));
});
