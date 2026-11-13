<?php

namespace App\Modules\Requests\Application\Actions;

use App\Models\User;
use App\Modules\Requests\Domain\RequestStatus;
use App\Modules\Requests\Models\ApprovalRequest;
use App\Platform\Audit\AuditLogger;
use App\Platform\Exceptions\DomainRuleViolation;
use App\Platform\Outbox\Outbox;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Sends a draft for a decision (ADR-0033). Only its requester. Idempotent:
 * submitting an already submitted request (a double click, a retried
 * request) changes nothing and announces nothing. The announcement goes
 * through the outbox, in the same transaction, so approvers are told
 * exactly when the submission is committed.
 */
class SubmitRequest
{
    public const TOPIC = 'requests.submitted';

    public function __construct(private readonly AuditLogger $audit, private readonly Outbox $outbox) {}

    public function handle(User $actor, ApprovalRequest $request): ApprovalRequest
    {
        return DB::transaction(function () use ($actor, $request): ApprovalRequest {
            $locked = ApprovalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->requester_id !== $actor->id) {
                throw new AuthorizationException;
            }

            if ($locked->status === RequestStatus::Submitted) {
                return $locked;
            }

            if (! $locked->status->canBecome(RequestStatus::Submitted)) {
                throw new DomainRuleViolation(__('This request has already been decided.'), 'status');
            }

            $locked->forceFill([
                'status' => RequestStatus::Submitted,
                'submitted_at' => now(),
                'lock_version' => $locked->lock_version + 1,
            ])->save();

            $this->audit->record('request.submitted', $locked, [], actor: $actor);
            $this->outbox->record(self::TOPIC, ['request' => $locked->uuid]);

            return $locked;
        });
    }
}
