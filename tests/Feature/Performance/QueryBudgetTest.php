<?php

use App\Models\User;
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
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route($page))
            ->assertOk();
    });

    expect(count($queries))->toBeLessThanOrEqual($budget, "{$page} ran ".count($queries)." queries:\n".implode("\n", $queries));
})->with([
    'home, guest' => ['home', false, 0],
    'login form' => ['login', false, 0],
    // Signed in: one indexed lookup of the person's roles, shared as
    // auth.permissions so the interface offers only what they may do
    // (ADR-0026). Raised from 0 in Phase 7 batch 2.
    'dashboard' => ['dashboard', true, 1],
    'profile settings' => ['profile.edit', true, 1],
    // Roles, and the passkey list.
    'security settings' => ['security.edit', true, 2],
]);
