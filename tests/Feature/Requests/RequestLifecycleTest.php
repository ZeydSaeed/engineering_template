<?php

use App\Access\Role;
use App\Models\User;
use App\Modules\Requests\Application\Actions\CreateRequest;
use App\Modules\Requests\Application\Actions\DecideRequest;
use App\Modules\Requests\Application\Actions\SubmitRequest;
use App\Modules\Requests\Application\Actions\UpdateDraft;
use App\Modules\Requests\Application\Jobs\NotifyApprovers;
use App\Modules\Requests\Domain\Decision;
use App\Modules\Requests\Domain\RequestStatus;
use App\Modules\Requests\Models\ApprovalRequest;
use App\Platform\Access\RoleAssignment;
use App\Platform\Audit\AuditEvent;
use App\Platform\Audit\VerifyAuditChain;
use App\Platform\Exceptions\DomainRuleViolation;
use App\Platform\Outbox\OutboxMessage;
use App\Platform\Outbox\OutboxRelay;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| The Requests module's rules (ADR-0033), through its actions: who may do
| what, in which state, with locks, audit records and outbox messages.
*/

uses(RefreshDatabase::class);

function approver(): User
{
    $user = User::factory()->create();
    RoleAssignment::query()->forceCreate(['user_id' => $user->id, 'role' => Role::Approver->value]);

    return $user;
}

/**
 * @return list<string>
 */
function auditedFor(ApprovalRequest $request): array
{
    return AuditEvent::query()
        ->where('subject_type', ApprovalRequest::MORPH)
        ->where('subject_id', (string) $request->id)
        ->orderBy('id')
        ->pluck('action')
        ->all();
}

describe('drafts', function () {
    it('starts as the requester\'s draft, with a UUIDv7 for URLs, and is audited', function () {
        $user = User::factory()->create();

        $request = app(CreateRequest::class)->handle($user, ['title' => '  New laptop  ', 'details' => 'For the support desk.', 'amount' => '1250.50']);

        expect($request->status)->toBe(RequestStatus::Draft)
            ->and($request->requester_id)->toBe($user->id)
            ->and($request->title)->toBe('New laptop')
            ->and($request->fresh()?->amount)->toBe('1250.50')
            ->and($request->uuid)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
            ->and($request->getRouteKeyName())->toBe('uuid')
            ->and(auditedFor($request))->toBe(['request.created']);
    });

    it('is changed by its requester only, while a draft, from the version they opened', function () {
        $request = ApprovalRequest::factory()->create(['title' => 'Old title']);
        $owner = $request->requester()->firstOrFail();
        $update = app(UpdateDraft::class);

        $changed = $update->handle($owner, $request, ['title' => 'New title', 'details' => 'More.'], 1);

        expect($changed->title)->toBe('New title')
            ->and($changed->lock_version)->toBe(2)
            ->and(auditedFor($request))->toBe(['request.updated']);

        // Saved again from a form opened before that change: refused.
        expect(fn () => $update->handle($owner, $request, ['title' => 'Stale', 'details' => 'More.'], 1))
            ->toThrow(DomainRuleViolation::class, 'Someone else changed this request');

        expect(fn () => $update->handle(User::factory()->create(), $request, ['title' => 'Mine now', 'details' => 'x'], 2))
            ->toThrow(AuthorizationException::class);

        // The same values again: nothing changes, nothing is recorded.
        $update->handle($owner, $request, ['title' => 'New title', 'details' => 'More.'], 2);
        expect($request->fresh()?->lock_version)->toBe(2)
            ->and(auditedFor($request))->toHaveCount(1);

        $request->forceFill(['status' => RequestStatus::Submitted, 'submitted_at' => now()])->save();
        expect(fn () => $update->handle($owner, $request, ['title' => 'Too late', 'details' => 'x'], 2))
            ->toThrow(DomainRuleViolation::class, 'Only a draft can be changed.');
    });
});

describe('submitting', function () {
    it('is done by the requester once, audited and announced once', function () {
        $request = ApprovalRequest::factory()->create();
        $owner = $request->requester()->firstOrFail();
        $submit = app(SubmitRequest::class);

        expect(fn () => $submit->handle(User::factory()->create(), $request))->toThrow(AuthorizationException::class);

        $submit->handle($owner, $request);
        $submit->handle($owner, $request);

        $fresh = $request->fresh();
        expect($fresh?->status)->toBe(RequestStatus::Submitted)
            ->and($fresh?->submitted_at)->not->toBeNull()
            ->and(auditedFor($request))->toBe(['request.submitted'])
            ->and(OutboxMessage::query()->where('topic', SubmitRequest::TOPIC)->count())->toBe(1);
    });

    it('tells every approver but the requester, once even if delivered twice', function () {
        $first = approver();
        $second = approver();
        // An approver asking for something is not asked to decide it.
        $request = ApprovalRequest::factory()->create(['requester_id' => $second->id]);
        app(SubmitRequest::class)->handle($second, $request);

        app(OutboxRelay::class)->relay();
        $message = OutboxMessage::query()->where('topic', SubmitRequest::TOPIC)->firstOrFail();
        (new NotifyApprovers($message->uuid, $message->payload))->handle();

        expect($first->notifications()->count())->toBe(1)
            ->and($first->notifications()->firstOrFail()->data)->toEqual([
                'kind' => 'request_submitted',
                'request' => $request->uuid,
                'title' => $request->title,
                'by' => $second->name,
            ])
            ->and($second->notifications()->count())->toBe(0);
    });
});

describe('deciding', function () {
    it('needs the right to decide, never on one\'s own request, and a reason to reject', function () {
        $decide = app(DecideRequest::class);
        $request = ApprovalRequest::factory()->submitted()->create();

        expect(fn () => $decide->handle(User::factory()->create(), $request, Decision::Approve))
            ->toThrow(AuthorizationException::class);

        $self = approver();
        $own = ApprovalRequest::factory()->submitted()->create(['requester_id' => $self->id]);
        expect(fn () => $decide->handle($self, $own, Decision::Approve))
            ->toThrow(DomainRuleViolation::class, 'You cannot decide your own request.');

        expect(fn () => $decide->handle(approver(), $request, Decision::Reject, '   '))
            ->toThrow(DomainRuleViolation::class, 'Say why the request is rejected.');

        $draft = ApprovalRequest::factory()->create();
        expect(fn () => $decide->handle(approver(), $draft, Decision::Approve))
            ->toThrow(DomainRuleViolation::class, 'Only a submitted request can be decided.');
    });

    it('approves once; the same click again changes nothing; another approver is told it is decided', function () {
        $decide = app(DecideRequest::class);
        $request = ApprovalRequest::factory()->submitted()->create();
        $approver = approver();

        $decide->handle($approver, $request, Decision::Approve);
        $decide->handle($approver, $request, Decision::Approve);

        expect(fn () => $decide->handle(approver(), $request, Decision::Reject, 'Too late'))
            ->toThrow(DomainRuleViolation::class, 'This request has already been decided.');

        $fresh = $request->fresh();
        expect($fresh?->status)->toBe(RequestStatus::Approved)
            ->and($fresh?->decided_by)->toBe($approver->id)
            ->and(auditedFor($request))->toBe(['request.approved'])
            ->and(OutboxMessage::query()->where('topic', DecideRequest::TOPIC)->count())->toBe(1);
    });

    it('rejects with the reason and tells the requester', function () {
        $request = ApprovalRequest::factory()->submitted()->create();
        $approver = approver();

        app(DecideRequest::class)->handle($approver, $request, Decision::Reject, 'Not this year.');
        app(OutboxRelay::class)->relay();

        $requester = $request->requester()->firstOrFail();
        expect($request->fresh()?->decision_note)->toBe('Not this year.')
            ->and($requester->notifications()->firstOrFail()->data)->toEqual([
                'kind' => 'request_rejected',
                'request' => $request->uuid,
                'title' => $request->title,
                'by' => $approver->name,
            ]);
    });

    it('stops counting a role the moment it is removed', function () {
        $approver = approver();
        $request = ApprovalRequest::factory()->submitted()->create();
        // The model has loaded its roles; the role goes before the click.
        expect($approver->hasPermission('requests.decide'))->toBeTrue();
        RoleAssignment::query()->where('user_id', $approver->id)->delete();

        expect(fn () => app(DecideRequest::class)->handle($approver, $request, Decision::Approve))
            ->toThrow(AuthorizationException::class);
    });

    it('keeps the audit chain whole through a whole life', function () {
        $request = app(CreateRequest::class)->handle(User::factory()->create(), ['title' => 'Training', 'details' => 'Two days.']);
        app(SubmitRequest::class)->handle($request->requester()->firstOrFail(), $request);
        app(DecideRequest::class)->handle(approver(), $request, Decision::Approve, 'Go ahead.');

        expect(auditedFor($request))->toBe(['request.created', 'request.submitted', 'request.approved'])
            ->and(app(VerifyAuditChain::class)->run()['broken_at'])->toBeNull();
    });
});
