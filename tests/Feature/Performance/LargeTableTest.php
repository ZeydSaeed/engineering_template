<?php

use App\Access\Role;
use App\Models\User;
use App\Platform\Access\RoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
| The user table with 10,000 rows on PostgreSQL (ADR-0026): each common
| request (first page, sort, search, filter, last page) stays within a
| fixed number of queries, a generous time limit and a memory limit. The
| measured figures are written to docs/evidence/phase-7; the limits only
| catch regressions such as a lost index or a query per row.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL only.');
    }
});

it('serves a 10,000-user table within its budgets', function (string $query) {
    $admin = User::factory()->withTwoFactor()->create(['name' => 'Admin']);
    RoleAssignment::query()->forceCreate(['user_id' => $admin->id, 'role' => Role::Administrator->value]);

    $now = now();
    $password = Hash::make('irrelevant-password');
    foreach (array_chunk(range(1, 10_000), 1_000) as $chunk) {
        DB::table('users')->insert(array_map(fn (int $i): array => [
            'name' => "Person {$i}",
            'email' => "person-{$i}@example.test",
            'password' => $password,
            'email_verified_at' => $now,
            'two_factor_confirmed_at' => $i % 3 === 0 ? null : $now,
            'created_at' => $now->copy()->subMinutes($i),
            'updated_at' => $now,
        ], $chunk));
    }
    DB::statement('analyze users');

    // Warm up the framework, then measure.
    $this->actingAs($admin)->get('/admin/users')->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $memoryBefore = memory_get_usage();
    $started = hrtime(true);

    $this->actingAs($admin)->get("/admin/users?{$query}")->assertOk();

    $milliseconds = (hrtime(true) - $started) / 1e6;
    $queries = count(DB::getQueryLog());
    $memory = (memory_get_peak_usage() - $memoryBefore) / 1024 / 1024;

    fwrite(STDERR, sprintf("\n[large-table] %-40s %6.1f ms %2d queries %5.1f MB peak growth", $query, $milliseconds, $queries, $memory));

    expect($queries)->toBeLessThanOrEqual(12)
        ->and($milliseconds)->toBeLessThan(1500)
        ->and($memory)->toBeLessThan(32);
})->with([
    'first page' => ['per_page=25'],
    'sorted by email, descending' => ['sort=email&direction=desc&per_page=100'],
    'search' => ['search=person-99&per_page=25'],
    'filter, no second factor' => ['filter[two_factor]=disabled&per_page=50'],
    'last page' => ['per_page=25&page=401'],
]);
