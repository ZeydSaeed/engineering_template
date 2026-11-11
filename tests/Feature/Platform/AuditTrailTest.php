<?php

use App\Models\User;
use App\Platform\Audit\AuditEvent;
use App\Platform\Audit\AuditLogger;
use App\Platform\Audit\VerifyAuditChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('records sign-in, failure and sign-out without what was typed', function () {
    $user = User::factory()->withTwoFactor()->create(['email' => 'person@example.test']);

    $this->post(route('login.store'), ['email' => 'Person@Example.test', 'password' => 'not-the-password']);
    $this->actingAs($user)->post(route('logout'));

    $failed = AuditEvent::query()->where('action', 'auth.failed')->sole();

    expect(AuditEvent::query()->pluck('action')->all())->toBe(['auth.failed', 'auth.logout'])
        ->and($failed->actor_id)->toBeNull()
        ->and($failed->metadata['identifier_hash'])->toHaveLength(32)
        ->and(json_encode($failed->getAttributes()))->not->toContain('person@example.test')->not->toContain('not-the-password');
});

it('fills actor, subject, request id and a chain of hashes', function () {
    $user = User::factory()->create();

    $first = app(AuditLogger::class)->record('test.first', $user, ['note' => 'one'], actor: $user);
    $second = app(AuditLogger::class)->record('test.second', $user, changes: ['name' => ['from' => 'A', 'to' => 'B']]);

    expect($first->previous_hash)->toBeNull()
        ->and($second->previous_hash)->toBe($first->hash)
        ->and($first->subject_type)->toBe(User::class)
        ->and($first->subject_id)->toBe((string) $user->id)
        ->and($second->actor_id)->toBeNull()
        ->and(app(VerifyAuditChain::class)->run())->toBe(['checked' => 2, 'broken_at' => null, 'reason' => null]);
});

it('masks secret keys passed as metadata', function () {
    $event = app(AuditLogger::class)->record('test.secret', metadata: ['token' => 'abc', 'nested' => ['password' => 'x', 'ok' => 1]]);

    // toEqual: PostgreSQL's jsonb returns keys in its own order.
    expect($event->refresh()->metadata)->toEqual(['token' => '[masked]', 'nested' => ['password' => '[masked]', 'ok' => 1]]);
});

it('detects a changed or removed record', function (string $tamper) {
    foreach (['a', 'b', 'c'] as $action) {
        app(AuditLogger::class)->record("test.{$action}", metadata: ['n' => $action]);
    }

    $second = AuditEvent::query()->orderBy('id')->skip(1)->firstOrFail();

    withoutAuditTriggers(function () use ($tamper, $second): void {
        $tamper === 'change'
            ? DB::table('audit_events')->where('id', $second->id)->update(['action' => 'test.innocent'])
            : DB::table('audit_events')->where('id', $second->id)->delete();
    });

    $result = app(VerifyAuditChain::class)->run();

    expect($result['broken_at'])->not->toBeNull();
    $this->artisan('platform:audit-verify')->assertFailed();
})->with(['change', 'delete']);

it('refuses to write without a key', function () {
    config(['platform.audit.hmac_key' => null]);

    app(AuditLogger::class)->record('test.nokey');
})->throws(LogicException::class, 'AUDIT_HMAC_KEY is not set');

/**
 * Tampering needs the triggers off, which only the table owner can do on
 * PostgreSQL; SQLite has none.
 */
function withoutAuditTriggers(Closure $callback): void
{
    $pgsql = DB::getDriverName() === 'pgsql';

    if ($pgsql) {
        DB::statement('ALTER TABLE audit_events DISABLE TRIGGER USER');
    }

    try {
        $callback();
    } finally {
        if ($pgsql) {
            DB::statement('ALTER TABLE audit_events ENABLE TRIGGER USER');
        }
    }
}
