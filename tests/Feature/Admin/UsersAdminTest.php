<?php

use App\Access\Role;
use App\Models\User;
use App\Platform\Access\RoleAssignment;
use App\Platform\Audit\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The user list for administrators (ADR-0026): access, the server-side table
| (#2), details with history (#16, #9) and bulk role changes (#23).
*/

uses(RefreshDatabase::class);

function userWithRole(?Role $role, array $attributes = []): User
{
    $user = User::factory()->withTwoFactor()->create($attributes);

    if ($role !== null) {
        RoleAssignment::query()->forceCreate(['user_id' => $user->id, 'role' => $role->value]);
    }

    return $user->refresh();
}

describe('access', function () {
    it('is for administrators only', function () {
        $this->actingAs(userWithRole(Role::Auditor))->get('/admin/users')->assertForbidden();
        $this->actingAs(userWithRole(null))->post('/admin/users/roles', ['action' => 'assign', 'role' => 'auditor', 'ids' => [1]])->assertForbidden();
    });

    it('sends guests to sign in', function () {
        $this->get('/admin/users')->assertRedirect('/login');
    });

    it('offers the permissions a person has to the interface', function () {
        $this->actingAs(userWithRole(Role::Administrator))
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('auth.permissions', ['audit.view', 'roles.manage']));
    });
});

describe('table', function () {
    beforeEach(function () {
        $this->admin = userWithRole(Role::Administrator, ['name' => 'Admin', 'email' => 'admin@example.test']);
        userWithRole(Role::Auditor, ['name' => 'Carla', 'email' => 'carla@example.test']);
        userWithRole(null, ['name' => 'Bashir', 'email' => 'bashir@example.test']);
        userWithRole(null, ['name' => '100% sure', 'email' => 'percent@example.test']);
    });

    it('sorts by a declared column and ignores any other', function () {
        $this->actingAs($this->admin)
            ->get('/admin/users?sort=email&direction=desc')
            ->assertInertia(fn (Assert $page) => $page
                ->where('state.sort', 'email')
                ->where('users.rows.0.email', 'percent@example.test'));

        $this->actingAs($this->admin)
            ->get('/admin/users?sort=password&direction=desc')
            ->assertInertia(fn (Assert $page) => $page
                ->where('state.sort', 'name')
                ->where('state.direction', 'asc'));
    });

    it('searches name and email, taking % literally', function () {
        $this->actingAs($this->admin)
            ->get('/admin/users?search=%25')
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.total', 1)
                ->where('users.rows.0.name', '100% sure'));

        $this->actingAs($this->admin)
            ->get('/admin/users?search=CARLA')
            ->assertInertia(fn (Assert $page) => $page->where('users.total', 1));
    });

    it('filters by role, by no role and by second factor', function (string $query, int $total) {
        $this->actingAs($this->admin)
            ->get("/admin/users?{$query}")
            ->assertInertia(fn (Assert $page) => $page->where('users.total', $total));
    })->with([
        ['filter[role]=administrator', 1],
        ['filter[role]=auditor', 1],
        ['filter[role]=none', 2],
        ['filter[role]=superuser', 4],
        ['filter[two_factor]=enabled', 4],
    ]);

    it('allows only the declared page sizes', function () {
        $this->actingAs($this->admin)
            ->get('/admin/users?per_page=5000')
            ->assertInertia(fn (Assert $page) => $page->where('state.per_page', 25));
    });

    it('loads the page with a fixed number of queries, however many rows', function () {
        User::factory()->withTwoFactor()->count(60)->create();

        DB::enableQueryLog();
        $this->actingAs($this->admin)->get('/admin/users?per_page=50')->assertOk();
        $queries = count(DB::getQueryLog());

        expect($queries)->toBeLessThanOrEqual(12);
    });
});

describe('details', function () {
    it('opens one user with roles and history, and no technical metadata', function () {
        $admin = userWithRole(Role::Administrator);
        $target = userWithRole(null, ['name' => 'Dana']);

        $this->actingAs($admin)->post('/admin/users/roles', ['action' => 'assign', 'role' => 'auditor', 'ids' => [$target->id]]);

        $this->actingAs($admin)
            ->get("/admin/users?user={$target->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.name', 'Dana')
                ->where('selected.roles.0.role', 'auditor')
                ->where('selected.timeline.0.action', 'access.role_assigned')
                ->where('selected.timeline.0.details', ['role' => 'auditor'])
                ->missing('selected.timeline.0.ip_address'));
    });

    it('is not loaded unless a user is opened', function () {
        $this->actingAs(userWithRole(Role::Administrator))
            ->get('/admin/users')
            ->assertInertia(fn (Assert $page) => $page->where('selected', null));
    });
});

describe('bulk roles', function () {
    it('reports who changed and who did not, and why', function () {
        $admin = userWithRole(Role::Administrator);
        $one = userWithRole(null);
        $two = userWithRole(null);

        $this->actingAs($admin)
            ->post('/admin/users/roles', ['action' => 'assign', 'role' => 'auditor', 'ids' => [$one->id, $admin->id, $two->id, 999999]])
            ->assertRedirect()
            ->assertSessionHas('inertia.flash_data.bulkResult', function (array $result) use ($one, $two, $admin) {
                return $result['succeeded'] === [$one->id, $two->id]
                    && array_column($result['failed'], 'id') === [$admin->id, 999999]
                    && $result['failed'][0]['reason'] === 'You cannot change your own roles.';
            });

        expect(RoleAssignment::query()->where('role', 'auditor')->count())->toBe(2)
            ->and(AuditEvent::query()->where('action', 'access.role_assigned')->count())->toBe(2);
    });

    it('keeps the last administrator', function () {
        $admin = userWithRole(Role::Administrator);
        $other = userWithRole(Role::Administrator);

        // Revoking both: the first goes, the second is the last one left.
        $this->actingAs($admin)->post('/admin/users/roles', ['action' => 'revoke', 'role' => 'administrator', 'ids' => [$other->id]]);
        $third = userWithRole(null);

        $this->actingAs($admin)
            ->post('/admin/users/roles', ['action' => 'revoke', 'role' => 'administrator', 'ids' => [$third->id]])
            ->assertSessionHas('inertia.flash_data.bulkResult.failed', []);

        expect(RoleAssignment::query()->where('role', 'administrator')->pluck('user_id')->all())->toBe([$admin->id]);
    });

    it('refuses more than 100 users or an unknown role', function (array $payload) {
        $this->actingAs(userWithRole(Role::Administrator))
            ->post('/admin/users/roles', $payload)
            ->assertSessionHasErrors();
    })->with([
        'too many' => [['action' => 'assign', 'role' => 'auditor', 'ids' => range(1, 101)]],
        'unknown role' => [['action' => 'assign', 'role' => 'superuser', 'ids' => [1]]],
        'unknown action' => [['action' => 'delete', 'role' => 'auditor', 'ids' => [1]]],
    ]);
});
