<?php

use App\Models\User;
use App\Modules\Requests\Models\ApprovalRequest;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
| The database keeps the request rules even when code does not (ADR-0033):
| each write below bypasses the actions, as a bug or a hand-written UPDATE
| would, and PostgreSQL refuses it. PostgreSQL only (CHECK constraints).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('CHECK constraints are tested on PostgreSQL (composer test:pgsql).');
    }
});

it('refuses a state the machine does not allow', function (Closure $change, string $constraint) {
    $request = ApprovalRequest::factory()->submitted()->create();

    expect(fn () => DB::table('approval_requests')->where('id', $request->id)->update($change($request)))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'unknown status' => [fn () => ['status' => 'cancelled'], 'approval_requests_status_check'],
    'submitted without a time' => [fn () => ['submitted_at' => null], 'approval_requests_submitted_check'],
    'approved by nobody' => [fn () => ['status' => 'approved'], 'approval_requests_decided_check'],
    'rejected without a reason' => [fn () => ['status' => 'rejected', 'decided_by' => User::factory()->create()->id, 'decided_at' => now()], 'approval_requests_rejection_note_check'],
    'decided by the requester' => [fn (ApprovalRequest $r) => ['status' => 'approved', 'decided_by' => $r->requester_id, 'decided_at' => now()], 'approval_requests_separation_check'],
    'decided before submitted' => [fn () => ['status' => 'approved', 'decided_by' => User::factory()->create()->id, 'decided_at' => now()->subDays(2)], 'approval_requests_decided_after_submitted_check'],
    'decided time without a decider' => [fn () => ['decided_at' => now()], 'approval_requests_decided_pair_check'],
    'negative amount' => [fn () => ['amount' => -1], 'approval_requests_amount_check'],
    'blank title' => [fn () => ['title' => '  '], 'approval_requests_title_check'],
]);

it('keeps a requester or decider with requests from being deleted', function () {
    $request = ApprovalRequest::factory()->submitted()->create();

    expect(fn () => DB::table('users')->where('id', $request->requester_id)->delete())
        ->toThrow(QueryException::class, 'approval_requests_requester_id_foreign');
});
