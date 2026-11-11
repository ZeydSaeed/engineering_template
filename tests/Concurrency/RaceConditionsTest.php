<?php

use App\Access\Role;
use App\Models\User;
use App\Platform\Access\RoleAssignment;
use App\Platform\Audit\AuditEvent;
use App\Platform\Audit\VerifyAuditChain;
use App\Platform\Exceptions\DomainRuleViolation;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxMessage;
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

    config(['platform.outbox.handlers' => ['test.counted' => CountingOutboxJob::class]]);
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
