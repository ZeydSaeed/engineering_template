<?php

use App\Access\Role;
use App\Models\User;
use App\Modules\Requests\Application\Actions\DecideRequest;
use App\Modules\Requests\Application\Actions\SubmitRequest;
use App\Modules\Requests\Domain\RequestStatus;
use App\Modules\Requests\Models\ApprovalRequest;
use App\Platform\Access\RoleAssignment;
use App\Platform\Audit\AuditEvent;
use App\Platform\Audit\VerifyAuditChain;
use App\Platform\Exceptions\DomainRuleViolation;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxMessage;
use App\Platform\Preferences\UserPreference;
use App\Platform\Preferences\WorkspacePreferences;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Tests\Support\Outbox\CountingOutboxJob;
use Tests\Support\Parallel;

/*
| Real races: separate PHP processes, one PostgreSQL database, a shared start
| time. Committed data (DatabaseMigrations, see tests/Pest.php), so every
| process sees it. PostgreSQL only: `composer test:pgsql`.
*/

beforeEach(function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Concurrency tests need PostgreSQL (composer test:pgsql).');
    }

    // Added to the application's handlers (modules register their own).
    config(['platform.outbox.handlers' => [...(array) config('platform.outbox.handlers'), 'test.counted' => CountingOutboxJob::class]]);
});

function newAdministrator(): User
{
    $user = User::factory()->withTwoFactor()->create();
    RoleAssignment::query()->forceCreate(['user_id' => $user->id, 'role' => Role::Administrator->value]);

    return $user;
}

it('lets exactly one of two administrators remove the other when both try at once', function () {
    foreach (range(1, 5) as $round) {
        $first = newAdministrator();
        $second = newAdministrator();

        $results = Parallel::run('revoke-administrator', [[$first->id, $second->id], [$second->id, $first->id]]);
        $succeeded = array_values(array_filter($results, fn (array $result): bool => $result['ok']));
        $refused = array_values(array_filter($results, fn (array $result): bool => ! $result['ok']));

        $stillAdministrators = RoleAssignment::query()
            ->whereIn('user_id', [$first->id, $second->id])
            ->where('role', Role::Administrator->value)
            ->count();

        expect($succeeded)->toHaveCount(1, "round {$round}")
            ->and($stillAdministrators)->toBe(1, "round {$round}")
            ->and($refused[0]['error'])->toBeIn([AuthorizationException::class, DomainRuleViolation::class]);
    }

    expect(app(VerifyAuditChain::class)->run()['broken_at'])->toBeNull();
});

it('grants a role once when six requests race', function () {
    $admin = newAdministrator();
    $target = User::factory()->create();

    $results = Parallel::run('assign-auditor', array_fill(0, 6, [$admin->id, $target->id]));

    expect(array_column($results, 'ok'))->each->toBeTrue()
        ->and(RoleAssignment::query()->where('user_id', $target->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'access.role_assigned')->count())->toBe(1)
        ->and(app(VerifyAuditChain::class)->run()['broken_at'])->toBeNull();
});

it('relays each outbox message once with four relays, and applies each once despite duplicates', function () {
    DB::transaction(function (): void {
        foreach (range(1, 300) as $n) {
            app(Outbox::class)->record('test.counted', ['n' => $n]);
        }
    });

    $relays = Parallel::run('relay-until-empty', array_fill(0, 4, [25]));

    expect(array_sum(array_column($relays, 'dispatched')))->toBe(300)
        ->and(OutboxMessage::query()->where('status', 'dispatched')->count())->toBe(300)
        ->and(DB::table('jobs')->where('queue', 'outbox')->count())->toBe(300);

    // Simulate at-least-once delivery: 50 messages delivered a second time.
    $bus = app(Dispatcher::class);
    OutboxMessage::query()->orderBy('id')->limit(50)->get()->each(
        fn (OutboxMessage $message) => $bus->dispatch((new CountingOutboxJob($message->uuid, $message->payload))->onConnection('database')),
    );

    Parallel::run('work-outbox-queue', array_fill(0, 3, []));

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('cache')->where('key', 'like', 'outbox-effect:%')->count())->toBe(300)
        ->and(DB::table('processed_messages')->count())->toBe(300);
});

it('keeps both of two workspace choices saved at the same moment', function () {
    foreach (range(1, 5) as $round) {
        $user = User::factory()->create();

        // The interface saves each choice with its own request (ADR-0028).
        $results = Parallel::run('save-preference', [
            [$user->id, 'density', '"compact"'],
            [$user->id, 'shortcuts', 'false'],
        ]);

        expect(array_column($results, 'ok'))->toBe([true, true], "round {$round}")
            ->and(UserPreference::read($user->id, WorkspacePreferences::KEY))
            ->toEqual(['density' => 'compact', 'shortcuts' => false], "round {$round}");
    }
});

it('lets exactly one of two approvers decide a request when both act at once', function () {
    foreach (range(1, 5) as $round) {
        $approvers = [newApprover(), newApprover()];
        $request = ApprovalRequest::factory()->submitted()->create();

        // One approves, the other rejects, at the same moment.
        $results = Parallel::run('decide-request', [
            [$approvers[0]->id, $request->id, 'approve'],
            [$approvers[1]->id, $request->id, 'reject'],
        ]);
        $succeeded = array_values(array_filter($results, fn (array $result): bool => $result['ok']));
        $refused = array_values(array_filter($results, fn (array $result): bool => ! $result['ok']));

        expect($succeeded)->toHaveCount(1, "round {$round}")
            ->and($refused[0]['error'])->toBe(DomainRuleViolation::class)
            ->and($refused[0]['message'])->toBe('This request has already been decided.')
            ->and($request->fresh()?->status)->toBeIn([RequestStatus::Approved, RequestStatus::Rejected])
            ->and(AuditEvent::query()->where('subject_id', (string) $request->id)->whereIn('action', ['request.approved', 'request.rejected'])->count())->toBe(1, "round {$round}")
            ->and(OutboxMessage::query()->where('topic', DecideRequest::TOPIC)->where('payload->request', $request->uuid)->count())->toBe(1, "round {$round}");
    }

    expect(app(VerifyAuditChain::class)->run()['broken_at'])->toBeNull();
});

it('submits a request once when four submissions race', function () {
    $request = ApprovalRequest::factory()->create();

    // Four, not six: with this process and the previous test's workers still
    // closing, six could pass the owner role's CONNECTION LIMIT 10 (seen
    // once: "too many connections for role"), which is the limit working.
    $results = Parallel::run('submit-request', array_fill(0, 4, [$request->requester_id, $request->id]));

    // Every attempt succeeds (the late ones change nothing); a failure shows its error.
    expect(array_values(array_filter($results, fn (array $result): bool => ! $result['ok'])))->toBe([])
        ->and($request->fresh()?->status)->toBe(RequestStatus::Submitted)
        ->and(AuditEvent::query()->where('subject_id', (string) $request->id)->where('action', 'request.submitted')->count())->toBe(1)
        ->and(OutboxMessage::query()->where('topic', SubmitRequest::TOPIC)->count())->toBe(1);
});

function newApprover(): User
{
    $user = User::factory()->create();
    RoleAssignment::query()->forceCreate(['user_id' => $user->id, 'role' => Role::Approver->value]);

    return $user;
}
