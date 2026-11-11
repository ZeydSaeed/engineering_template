<?php

use App\Access\Role;
use App\Models\User;
use App\Platform\Access\AssignRole;
use App\Platform\Access\RevokeRole;
use App\Platform\Access\RoleAssignment;
use App\Platform\Audit\AuditEvent;
use App\Platform\Exceptions\DomainRuleViolation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

function administrator(): User
{
    $user = User::factory()->withTwoFactor()->create();
    RoleAssignment::query()->forceCreate(['user_id' => $user->id, 'role' => Role::Administrator->value]);

    return $user->refresh();
}

it('registers one Gate ability per permission', function () {
    $admin = administrator();
    $auditor = User::factory()->create();
    RoleAssignment::query()->forceCreate(['user_id' => $auditor->id, 'role' => Role::Auditor->value]);

    expect(Gate::forUser($admin)->allows('roles.manage'))->toBeTrue()
        ->and(Gate::forUser($auditor->refresh())->allows('roles.manage'))->toBeFalse()
        ->and(Gate::forUser($auditor)->allows('audit.view'))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('audit.view'))->toBeFalse();
});

it('lets an administrator grant a role once, and audits it', function () {
    $admin = administrator();
    $user = User::factory()->create();

    app(AssignRole::class)->handle($admin, $user, Role::Auditor);
    app(AssignRole::class)->handle($admin, $user, Role::Auditor);

    expect($user->hasRole(Role::Auditor))->toBeTrue()
        ->and(RoleAssignment::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'access.role_assigned')->get())
        ->toHaveCount(1)
        ->sequence(fn ($event) => $event->actor_id->toBe($admin->id)->subject_id->toBe((string) $user->id)->metadata->toBe(['role' => 'auditor']));
});

it('refuses role changes from someone without the permission', function () {
    $auditor = User::factory()->create();
    RoleAssignment::query()->forceCreate(['user_id' => $auditor->id, 'role' => Role::Auditor->value]);

    app(AssignRole::class)->handle($auditor->refresh(), User::factory()->create(), Role::Administrator);
})->throws(AuthorizationException::class);

it('refuses changes to your own roles', function () {
    $admin = administrator();

    app(RevokeRole::class)->handle($admin, $admin, Role::Administrator);
})->throws(DomainRuleViolation::class, 'You cannot change your own roles.');

it('lets one of two administrators remove the other, who then can no longer act', function () {
    $first = administrator();
    $second = administrator();

    app(RevokeRole::class)->handle($first, $second, Role::Administrator);

    expect($second->hasRole(Role::Administrator))->toBeFalse()
        ->and(AuditEvent::query()->where('action', 'access.role_revoked')->count())->toBe(1);

    // The actor's own grants are re-read under the lock, not taken from memory.
    expect(fn () => app(RevokeRole::class)->handle($second, $first, Role::Administrator))
        ->toThrow(AuthorizationException::class);
});

it('never removes the last administrator, whoever asks', function () {
    // A non-administrator role manager, so the request reaches the rule.
    config(['platform.access.manage_permission' => 'audit.view']);

    $admin = administrator();
    $auditor = User::factory()->create();
    RoleAssignment::query()->forceCreate(['user_id' => $auditor->id, 'role' => Role::Auditor->value]);

    app(RevokeRole::class)->handle($auditor->refresh(), $admin, Role::Administrator);
})->throws(DomainRuleViolation::class, 'The last administrator cannot be removed.');
