<?php

use App\Access\Role;
use App\Models\User;
use App\Notifications\RoleChanged;
use App\Platform\Access\RoleAssignment;
use App\Platform\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Notifications (#8), dashboard widgets (#1) and reports (#21), ADR-0029.
*/

uses(RefreshDatabase::class);

function personWithRole(?Role $role = null): User
{
    $user = User::factory()->withTwoFactor()->create();

    if ($role) {
        RoleAssignment::query()->forceCreate(['user_id' => $user->id, 'role' => $role->value]);
    }

    return $user->refresh();
}

describe('notifications', function () {
    it('tells a person when a role really changes, and only then', function () {
        $admin = personWithRole(Role::Administrator);
        $person = personWithRole();

        $this->actingAs($admin)->post('/admin/users/roles', ['action' => 'assign', 'role' => 'auditor', 'ids' => [$person->id]]);
        // Already held: no change, no news.
        $this->actingAs($admin)->post('/admin/users/roles', ['action' => 'assign', 'role' => 'auditor', 'ids' => [$person->id]]);
        $this->actingAs($admin)->post('/admin/users/roles', ['action' => 'revoke', 'role' => 'auditor', 'ids' => [$person->id]]);

        $kinds = $person->notifications()->reorder()->oldest()->get()->map(fn ($n) => $n->data['kind'])->all();
        expect($kinds)->toBe(['role_granted', 'role_removed'])
            ->and($person->notifications()->first()->type)->toBe(RoleChanged::class);
    });

    it('lists, counts and marks read for their owner only', function () {
        $person = personWithRole();
        $other = personWithRole();
        $person->notify(new RoleChanged('role_granted', 'auditor', 'Admin'));
        $person->notify(new RoleChanged('role_removed', 'auditor', 'Admin'));
        $id = $person->notifications()->first()->id;

        $this->actingAs($person)
            ->get('/notifications')
            ->assertInertia(fn (Assert $page) => $page
                ->has('items', 2)
                ->where('notifications.unread', 2));

        $this->actingAs($other)->post("/notifications/{$id}/read")->assertNotFound();
        $this->actingAs($person)->post("/notifications/{$id}/read")->assertRedirect();
        expect($person->unreadNotifications()->count())->toBe(1);

        $this->actingAs($person)->post('/notifications/read-all')->assertRedirect();
        expect($person->unreadNotifications()->count())->toBe(0);
    });
});

describe('dashboard', function () {
    it('offers the people widget only to those who manage roles', function () {
        $this->actingAs(personWithRole())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('layout.available', ['notifications', 'activity', 'documents'])
                ->missing('widgets.people'));

        $this->actingAs(personWithRole(Role::Administrator))
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('layout.available', ['notifications', 'activity', 'documents', 'people'])
                ->where('widgets.people.administrators', 1));
    });

    it('keeps each person\'s order and hidden widgets, and computes only what shows', function () {
        $person = personWithRole();

        $this->actingAs($person)
            ->put('/dashboard/widgets', ['order' => ['documents', 'people', 'activity'], 'hidden' => ['activity', 'unknown']])
            ->assertRedirect('/dashboard');

        DB::enableQueryLog();
        $this->actingAs($person)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                // "people" is not theirs to see; new widgets go to the end.
                ->where('layout.order', ['documents', 'activity', 'notifications'])
                ->where('layout.hidden', ['activity'])
                ->missing('widgets.activity')
                ->has('widgets.documents'));

        $auditReads = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'audit_events'));
        expect($auditReads)->toHaveCount(0);
    });
});

describe('reports', function () {
    it('is for those who may read the audit trail', function () {
        $this->actingAs(personWithRole())->get('/admin/reports')->assertForbidden();
        $this->actingAs(personWithRole(Role::Auditor))->get('/admin/reports')->assertOk();
    });

    it('counts sign-ins per day with empty days as zero, and roles', function () {
        $auditor = personWithRole(Role::Auditor);
        personWithRole(Role::Administrator);
        personWithRole();
        $logger = app(AuditLogger::class);
        $logger->record('auth.login', $auditor, actor: $auditor);
        $logger->record('auth.login', $auditor, actor: $auditor);

        $this->actingAs($auditor)
            ->get('/admin/reports?days=7')
            ->assertInertia(fn (Assert $page) => $page
                ->where('days', 7)
                ->has('signIns', 7)
                ->where('signIns.6.count', 2)
                ->where('signIns.0.count', 0)
                ->where('roles', [
                    ['role' => 'administrator', 'count' => 1],
                    ['role' => 'auditor', 'count' => 1],
                    // Every role is listed, held or not (Requests module, ADR-0033).
                    ['role' => 'approver', 'count' => 0],
                    ['role' => 'none', 'count' => 1],
                ]));
    });

    it('allows only the offered periods', function () {
        $this->actingAs(personWithRole(Role::Auditor))
            ->get('/admin/reports?days=10000')
            ->assertInertia(fn (Assert $page) => $page->where('days', 30)->has('signIns', 30));
    });
});
