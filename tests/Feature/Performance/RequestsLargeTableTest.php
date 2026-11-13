<?php

use App\Access\Role;
use App\Models\User;
use App\Modules\Requests\Domain\RequestStatus;
use App\Modules\Requests\Models\ApprovalRequest;
use App\Platform\Access\RoleAssignment;
use App\Platform\Observability\PlatformMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The Requests module with 10,000 requests from 200 people on PostgreSQL
| (ADR-0033): the approver's queue, sorting, searching, the last page, a
| requester's own list, one request, the dashboard and the metrics each
| stay within a fixed number of queries, a generous time limit and a memory
| limit; the read paths use indexes. The figures are written to
| docs/evidence/phase-9; the limits only catch regressions such as a lost
| index or a query per row.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL only.');
    }

    $this->approver = User::factory()->withTwoFactor()->create(['name' => 'Approver']);
    RoleAssignment::query()->forceCreate(['user_id' => $this->approver->id, 'role' => Role::Approver->value]);

    $now = now();
    $password = Hash::make('irrelevant-password');
    $secret = encrypt('JBSWY3DPEHPK3PXP');
    DB::table('users')->insert(array_map(fn (int $i): array => [
        'name' => "Requester {$i}",
        'email' => "requester-{$i}@example.test",
        'password' => $password,
        'email_verified_at' => $now,
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ], range(1, 200)));
    $requesters = User::query()->where('email', 'like', 'requester-%')->orderBy('id')->pluck('id')->all();
    // Requester 3 has rows i = 3, 203, 403, ...: fifty, all rejected.
    $this->requester = User::query()->findOrFail($requesters[3]);

    // A quarter in each status; every row satisfies the CHECK constraints.
    $statuses = RequestStatus::cases();
    $approverId = $this->approver->id;
    foreach (array_chunk(range(1, 10_000), 1_000) as $chunk) {
        DB::table('approval_requests')->insert(array_map(function (int $i) use ($requesters, $statuses, $now, $approverId): array {
            $status = $statuses[$i % 4];
            $created = $now->copy()->subMinutes(20_000 - $i);
            $submitted = $status === RequestStatus::Draft ? null : $created->copy()->addMinutes(5);
            $decided = $status->isFinal() ? $created->copy()->addMinutes(9) : null;

            return [
                'uuid' => (string) Str::uuid7(),
                'requester_id' => $requesters[$i % 200],
                'title' => "Request number {$i}",
                'details' => 'Generated for the large-table test.',
                'amount' => $i % 3 === 0 ? null : number_format($i * 1.5, 2, '.', ''),
                'status' => $status->value,
                'submitted_at' => $submitted,
                'decided_by' => $decided === null ? null : $approverId,
                'decided_at' => $decided,
                'decision_note' => $status === RequestStatus::Rejected ? 'Not now.' : null,
                'lock_version' => 1,
                'created_at' => $created,
                'updated_at' => $created,
            ];
        }, $chunk));
    }
    DB::statement('analyze approval_requests');
    DB::statement('analyze users');
});

/**
 * @return array{ms: float, queries: int, mb: float}
 */
function measureRequestsCall(callable $call): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $memoryBefore = memory_get_usage();
    $started = hrtime(true);

    $call();

    return [
        'ms' => (hrtime(true) - $started) / 1e6,
        'queries' => count(DB::getQueryLog()),
        'mb' => (memory_get_peak_usage() - $memoryBefore) / 1024 / 1024,
    ];
}

/**
 * @param  array{ms: float, queries: int, mb: float}  $figures
 */
function reportRequestsCall(string $label, array $figures): void
{
    fwrite(STDERR, sprintf("\n[requests-10k] %-44s %6.1f ms %2d queries %5.1f MB peak growth", $label, $figures['ms'], $figures['queries'], $figures['mb']));
}

it('serves the requests list to an approver within its budgets', function (string $query) {
    // Warm up the framework, then measure.
    $this->actingAs($this->approver)->get('/requests')->assertOk();

    $figures = measureRequestsCall(fn () => $this->actingAs($this->approver)->get("/requests?{$query}")->assertOk());
    reportRequestsCall($query, $figures);

    expect($figures['queries'])->toBeLessThanOrEqual(12)
        ->and($figures['ms'])->toBeLessThan(1500)
        ->and($figures['mb'])->toBeLessThan(32);
})->with([
    'first page' => ['per_page=25'],
    'waiting for me, oldest first' => ['filter[scope]=to_decide&sort=submitted_at&direction=asc&per_page=25'],
    'rejected, by amount' => ['filter[status]=rejected&sort=amount&direction=desc&per_page=100'],
    'search' => ['search=number%2099&per_page=25'],
    'last page' => ['per_page=25&page=400'],
]);

it('serves a requester\'s own list, one request, the dashboard and the metrics within their budgets', function () {
    $own = ApprovalRequest::query()->where('requester_id', $this->requester->id)->where('status', 'rejected')->firstOrFail();

    $this->actingAs($this->requester)->get('/requests')->assertOk();

    $cases = [
        'own list (50 of 10,000)' => fn () => $this->actingAs($this->requester)->get('/requests?per_page=25')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('requests.total', 50)),
        'one request with history' => fn () => $this->actingAs($this->requester)->get("/requests/{$own->uuid}")->assertOk(),
        'dashboard (approver widget)' => fn () => $this->actingAs($this->approver)->get('/dashboard')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('widgets.requests.to_decide', 2_500)),
        'metrics scrape' => fn () => expect(app(PlatformMetrics::class)->render())->toContain('module_requests_by_status{status="submitted"} 2500'),
    ];

    foreach ($cases as $label => $call) {
        $figures = measureRequestsCall($call);
        reportRequestsCall($label, $figures);

        // The second argument of these matchers is the expected value or a
        // message only for toBeTrue; keep the label in the report above.
        expect($figures['queries'] <= 12 && $figures['ms'] < 1500 && $figures['mb'] < 32)->toBeTrue("{$label} over budget");
    }
});

it('reads the approver\'s queue, the waiting time and a requester\'s list through indexes', function () {
    $plans = [
        'queue' => "explain select * from approval_requests where status = 'submitted' and requester_id <> {$this->approver->id} order by submitted_at asc limit 25",
        'oldest waiting' => "explain select min(submitted_at) from approval_requests where status = 'submitted'",
        'own list' => "explain select * from approval_requests where requester_id = {$this->requester->id} order by created_at desc limit 25",
    ];

    foreach ($plans as $label => $sql) {
        $plan = implode("\n", array_map(fn (object $row): string => (string) $row->{'QUERY PLAN'}, DB::select($sql)));
        fwrite(STDERR, "\n[requests-10k] plan, {$label}:\n{$plan}");

        expect(str_contains($plan, 'Seq Scan on approval_requests'))->toBeFalse("{$label}:\n{$plan}");
    }
});
