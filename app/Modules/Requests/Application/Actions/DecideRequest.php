<?php

namespace App\Modules\Requests\Application\Actions;

use App\Access\Permission;
use App\Models\User;
use App\Modules\Requests\Domain\Decision;
use App\Modules\Requests\Models\ApprovalRequest;
use App\Platform\Audit\AuditLogger;
use App\Platform\Exceptions\DomainRuleViolation;
use App\Platform\Outbox\Outbox;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Approves or rejects a submitted request (ADR-0033).
 *
 * - Only someone who may decide (requests.decide), re-checked inside the
 *   transaction, so a role removed a moment ago no longer counts.
 * - Never one's own request (also a CHECK constraint).
 * - The row is locked and its state read again under the lock: when two
 *   approvers decide at once, the second finds it decided and is told so.
 * - Idempotent for the same person and the same decision (a double click).
 * - A rejection needs a reason.
 * - Audited, and announced through the outbox in the same transaction.
 */
class DecideRequest
{
    public const TOPIC = 'requests.decided';

    public function __construct(private readonly AuditLogger $audit, private readonly Outbox $outbox) {}

    public function handle(User $actor, ApprovalRequest $request, Decision $decision, ?string $note = null): ApprovalRequest
    {
        $note = $note === null ? null : trim($note);

        if ($decision->needsNote() && ($note === null || $note === '')) {
            throw new DomainRuleViolation(__('Say why the request is rejected.'), 'note');
        }

        return DB::transaction(function () use ($actor, $request, $decision, $note): ApprovalRequest {
            $locked = ApprovalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            $actor->flushRoles();

            if (! $actor->hasPermission(Permission::RequestsDecide)) {
                throw new AuthorizationException;
            }

            if ($locked->requester_id === $actor->id) {
                throw new DomainRuleViolation(__('You cannot decide your own request.'), 'decision');
            }

            if ($locked->status === $decision->outcome() && $locked->decided_by === $actor->id) {
                return $locked;
            }

            if ($locked->status->isFinal()) {
                throw new DomainRuleViolation(__('This request has already been decided.'), 'decision');
            }

            if (! $locked->status->canBecome($decision->outcome())) {
                throw new DomainRuleViolation(__('Only a submitted request can be decided.'), 'decision');
            }

            $locked->forceFill([
                'status' => $decision->outcome(),
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note === '' ? null : $note,
                'lock_version' => $locked->lock_version + 1,
            ])->save();

            match ($decision) {
                Decision::Approve => $this->audit->record('request.approved', $locked, [], actor: $actor),
                Decision::Reject => $this->audit->record('request.rejected', $locked, [], actor: $actor),
            };
            $this->outbox->record(self::TOPIC, ['request' => $locked->uuid, 'decision' => $decision->value]);

            return $locked;
        });
    }
}
