<?php

use App\Access\Role;
use App\Models\User;
use App\Platform\Access\RoleAssignment;
use App\Platform\Preferences\UserPreference;
use App\Platform\Tables\SavedView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

/*
| A person's table columns (#3) and saved views (#4), ADR-0026: only tables
| they may see, only declared columns and filters, only their own views.
*/

uses(RefreshDatabase::class);

function tableAdmin(): User
{
    $user = User::factory()->withTwoFactor()->create();
    RoleAssignment::query()->forceCreate(['user_id' => $user->id, 'role' => Role::Administrator->value]);

    return $user->refresh();
}

describe('columns', function () {
    it('keeps the order and hidden columns, reconciled with the table', function () {
        $admin = tableAdmin();

        $this->actingAs($admin)->put('/tables/users/columns', [
            'order' => ['created_at', 'unknown', 'email', 'name'],
            // name may not be hidden; unknown is not a column.
            'hidden' => ['roles', 'name', 'unknown'],
        ])->assertRedirect();

        expect(UserPreference::read($admin->id, 'table:users:columns'))->toBe([
            'order' => ['created_at', 'email', 'name', 'roles', 'two_factor'],
            'hidden' => ['roles'],
        ]);

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertInertia(fn (Assert $page) => $page->where('columns.hidden', ['roles']));
    });

    it('refuses a table the person may not see, and an unknown table', function () {
        $this->actingAs(User::factory()->withTwoFactor()->create())
            ->put('/tables/users/columns', ['order' => [], 'hidden' => []])
            ->assertForbidden();

        $this->actingAs(tableAdmin())
            ->put('/tables/nothing/columns', ['order' => [], 'hidden' => []])
            ->assertNotFound();
    });
});

describe('saved views', function () {
    it('stores only what the table allows, and replaces a view of the same name', function () {
        $admin = tableAdmin();
        $payload = fn (string $sort) => [
            'name' => 'Auditors',
            'state' => ['search' => 'x', 'filters' => ['role' => 'auditor', 'evil' => '1'], 'sort' => $sort, 'direction' => 'desc', 'per_page' => 50],
            'columns' => ['order' => ['email'], 'hidden' => ['created_at']],
        ];

        $this->actingAs($admin)->post('/tables/users/views', $payload('password'))->assertRedirect();
        $this->actingAs($admin)->post('/tables/users/views', $payload('email'))->assertRedirect();

        $views = SavedView::query()->where('user_id', $admin->id)->get();
        expect($views)->toHaveCount(1)
            ->and($views[0]->state['filters'])->toBe(['role' => 'auditor'])
            ->and($views[0]->state['sort'])->toBe('email')
            ->and($views[0]->state['columns']['hidden'])->toBe(['created_at']);
    });

    it('keeps at most 20 views per table', function () {
        $admin = tableAdmin();

        foreach (range(1, SavedView::MAX_PER_TABLE) as $i) {
            SavedView::query()->create(['user_id' => $admin->id, 'table' => 'users', 'name' => "v{$i}", 'state' => []]);
        }

        $this->actingAs($admin)
            ->post('/tables/users/views', ['name' => 'one more', 'state' => ['sort' => 'name']])
            ->assertSessionHasErrors('name');
    });

    it('never shows or deletes another person\'s view', function () {
        $owner = tableAdmin();
        $other = tableAdmin();
        $view = SavedView::query()->create(['user_id' => $owner->id, 'table' => 'users', 'name' => 'Mine', 'state' => []]);

        $this->actingAs($other)
            ->get('/admin/users')
            ->assertInertia(fn (Assert $page) => $page->where('views', []));

        $this->actingAs($other)->delete("/tables/users/views/{$view->id}")->assertNotFound();
        expect(SavedView::query()->whereKey($view->id)->exists())->toBeTrue();

        $this->actingAs($owner)->delete("/tables/users/views/{$view->id}")->assertRedirect();
        expect(SavedView::query()->whereKey($view->id)->exists())->toBeFalse();
    });
});
