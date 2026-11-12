<?php

use App\Access\Role;
use App\Models\User;
use App\Platform\Access\RoleAssignment;
use App\Platform\Preferences\UserPreference;
use App\Platform\Preferences\WorkspacePreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Workspace preferences (#28) and global search (#5), ADR-0028.
*/

uses(RefreshDatabase::class);

describe('preferences', function () {
    it('saves only declared choices and applies them over the cookies', function () {
        $user = User::factory()->withTwoFactor()->create();

        $this->actingAs($user)
            ->put('/settings/preferences', ['locale' => 'ar', 'appearance' => 'dark', 'density' => 'compact', 'shortcuts' => false])
            ->assertRedirect()
            ->assertCookie('locale', 'ar', false)
            ->assertCookie('appearance', 'dark', false);

        // PostgreSQL keeps jsonb keys in its own order: compare as a map.
        expect(UserPreference::read($user->id, WorkspacePreferences::KEY))
            ->toEqual(['locale' => 'ar', 'appearance' => 'dark', 'density' => 'compact', 'shortcuts' => false]);

        // The saved choice wins over a cookie saying otherwise.
        $this->actingAs($user)
            ->withUnencryptedCookie('locale', 'en')
            ->withUnencryptedCookie('appearance', 'light')
            ->get('/dashboard')
            ->assertSee('<html lang="ar" dir="rtl" data-density="compact" class="dark"', false)
            ->assertInertia(fn (Assert $page) => $page
                ->where('preferences.density', 'compact')
                ->where('preferences.shortcuts', false)
                ->where('preferences.saved', true));
    });

    it('refuses values that are not offered', function (array $payload) {
        $this->actingAs(User::factory()->withTwoFactor()->create())
            ->put('/settings/preferences', $payload)
            ->assertSessionHasErrors();
    })->with([
        [['locale' => 'fr']],
        [['appearance' => 'neon']],
        [['density' => 'tiny']],
        [['shortcuts' => 'maybe']],
    ]);

    it('changes only what is sent', function () {
        $user = User::factory()->withTwoFactor()->create();

        $this->actingAs($user)->put('/settings/preferences', ['density' => 'compact']);
        $this->actingAs($user)->put('/settings/preferences', ['shortcuts' => false]);

        expect(UserPreference::read($user->id, WorkspacePreferences::KEY))->toEqual(['density' => 'compact', 'shortcuts' => false]);
    });

    it('reads the saved choices once per session', function () {
        $user = User::factory()->withTwoFactor()->create();
        UserPreference::write($user->id, WorkspacePreferences::KEY, ['density' => 'compact']);

        $this->actingAs($user)->get('/dashboard')->assertOk();

        DB::enableQueryLog();
        $this->actingAs($user)->get('/dashboard')->assertOk();
        $reads = collect(DB::getQueryLog())->filter(fn (array $query) => str_contains($query['query'], 'user_preferences'));

        expect($reads)->toHaveCount(0);
    });

    it('leaves guests with the cookies and offers no saving', function () {
        $this->withUnencryptedCookie('locale', 'en')
            ->get('/')
            ->assertSee('<html lang="en" dir="ltr" data-density="comfortable"', false)
            ->assertInertia(fn (Assert $page) => $page->where('preferences.saved', false));
    });
});

describe('search', function () {
    it('finds people for those who manage roles, and nothing for others', function () {
        $admin = User::factory()->withTwoFactor()->create(['name' => 'Admin']);
        RoleAssignment::query()->forceCreate(['user_id' => $admin->id, 'role' => Role::Administrator->value]);
        User::factory()->create(['name' => 'Nadia Hassan', 'email' => 'nadia@example.test']);

        $this->actingAs($admin)
            ->getJson('/search?q=nadia')
            ->assertOk()
            ->assertJsonPath('users.0.name', 'Nadia Hassan')
            ->assertJsonPath('users.0.url', fn (string $url) => str_contains($url, '/admin/users?') && str_contains($url, 'user='));

        $this->actingAs(User::factory()->withTwoFactor()->create())
            ->getJson('/search?q=nadia')
            ->assertOk()
            ->assertExactJson(['users' => []]);
    });

    it('needs two characters, takes % literally and returns a few results', function () {
        $admin = User::factory()->withTwoFactor()->create(['name' => 'Admin']);
        RoleAssignment::query()->forceCreate(['user_id' => $admin->id, 'role' => Role::Administrator->value]);
        User::factory()->count(8)->create(['name' => 'Same Name']);

        $this->actingAs($admin)->getJson('/search?q=S')->assertExactJson(['users' => []]);
        $this->actingAs($admin)->getJson('/search?q=%25%25')->assertExactJson(['users' => []]);
        $this->actingAs($admin)->getJson('/search?q=Same')->assertJsonCount(5, 'users');
    });

    it('is for signed-in people and limited to 60 a minute', function () {
        $this->getJson('/search?q=ab')->assertUnauthorized();

        $user = User::factory()->withTwoFactor()->create();
        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($user)->getJson('/search?q=ab');
        }
        $this->actingAs($user)->getJson('/search?q=ab')->assertStatus(429);
    });
});
