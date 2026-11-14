<?php

use App\Access\Role;
use App\Models\User;
use App\Modules\Requests\Models\ApprovalRequest;
use App\Platform\Access\RoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The requests list takes search, filters, sort and paging from the URL
| (ADR-0026). The signed-in ZAP scan (Phase 10 follow-ups) reported a
| possible SQL injection there; whatever ZAP sends, each value must be data:
| never a server error, never more rows than the plain search finds, and
| sort and filter values outside their lists ignored.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->approver = User::factory()->withTwoFactor()->create();
    RoleAssignment::query()->forceCreate(['user_id' => $this->approver->id, 'role' => Role::Approver->value]);
    ApprovalRequest::factory()->count(3)->create(['title' => 'Laptop stand']);
    ApprovalRequest::factory()->count(2)->create(['title' => 'Office chair']);
});

/** Total rows the list reports for these query parameters. */
function listTotal(User $viewer, array $query): int
{
    $total = null;

    test()->actingAs($viewer)->get('/requests?'.http_build_query($query))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$total) {
            $total = $page->toArray()['props']['requests']['total'];
        });

    return (int) $total;
}

it('treats injection payloads in the search as text', function (string $payload) {
    // Every payload contains text no title has: as data, it matches nothing.
    expect(listTotal($this->approver, ['search' => $payload]))->toBe(0);
})->with([
    "' OR '1'='1",
    "x' OR 1=1 --",
    'x" OR "1"="1',
    "Laptop' AND 1=1 --",
    "Laptop%' AND '1'='1",
    "'; DROP TABLE approval_requests; --",
    "x' UNION SELECT null, version() --",
    "x' AND pg_sleep(2) IS NULL --",
    "Laptop') OR ('1'='1",
]);

it('finds what the search text says, % and _ included, and nothing more', function () {
    expect(listTotal($this->approver, ['search' => 'Laptop']))->toBe(3)
        ->and(listTotal($this->approver, ['search' => 'laptop stand']))->toBe(3)
        ->and(listTotal($this->approver, ['search' => '%']))->toBe(0)
        ->and(listTotal($this->approver, ['search' => '_']))->toBe(0);
});

it('ignores sort, direction and filter values outside their lists', function (array $query) {
    expect(listTotal($this->approver, $query))->toBe(5);
})->with([
    'sort' => [['sort' => 'title; DROP TABLE users']],
    'sort with a subquery' => [['sort' => '(select 1)']],
    'direction' => [['sort' => 'title', 'direction' => 'desc, (select pg_sleep(2))']],
    'status filter' => [['filter' => ['status' => "draft' OR '1'='1"]]],
    'scope filter' => [['filter' => ['scope' => 'mine OR 1=1']]],
    'per page' => [['per_page' => '25 OR 1=1']],
    'page' => [['page' => "1' OR '1'='1"]],
]);
