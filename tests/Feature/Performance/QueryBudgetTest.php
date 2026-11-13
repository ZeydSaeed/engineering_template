<?php

use App\Models\User;
use App\Platform\Preferences\WorkspacePreferences;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
| How many statements each page may run (docs/standards/performance.md). A
| change that adds queries fails here; raise a budget only on purpose, in
| the same reviewed change. Counts are the page's own queries: tests keep
| the session in memory and sign in with actingAs(), so a real request adds
| one session read and write and one user lookup on top.
*/

uses(RefreshDatabase::class);

/**
 * @return list<string>
 */
function queriesDuring(Closure $callback): array
{
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $callback();

    return $queries;
}

it('keeps each page within its query budget', function (string $page, bool $signedIn, int $budget) {
    $user = User::factory()->withTwoFactor()->create();

    $queries = queriesDuring(function () use ($page, $signedIn, $user): void {
        ($signedIn ? $this->actingAs($user) : $this)
            ->withSession([
                'auth.password_confirmed_at' => time(),
                // A page within a session: the first request of a session
                // also reads the saved preferences once (ADR-0028,
                // WorkspacePreferencesTest).
                WorkspacePreferences::SESSION_KEY => ['user' => $user->id, 'values' => []],
            ])
            ->get(route($page))
            ->assertOk();
    });

    expect(count($queries))->toBeLessThanOrEqual($budget, "{$page} ran ".count($queries)." queries:\n".implode("\n", $queries));
})->with([
    'home, guest' => ['home', false, 0],
    'login form' => ['login', false, 0],
    // Every signed-in page: the person's roles (auth.permissions, ADR-0026,
    // Phase 7 batch 2) and the unread notification count (ADR-0029, batch
    // 4b), both on indexes.
    // The dashboard adds its layout and the visible widgets: three unread
    // notifications, activity (about and by the person), document counts,
    // and the person's requests by status (Requests module, ADR-0033). An
    // approver's dashboard runs one more: the requests waiting for them.
    'dashboard' => ['dashboard', true, 8],
    'profile settings' => ['profile.edit', true, 2],
    // And the passkey list.
    'security settings' => ['security.edit', true, 3],
]);
