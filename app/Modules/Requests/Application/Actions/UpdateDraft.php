<?php

namespace App\Modules\Requests\Application\Actions;

use App\Models\User;
use App\Modules\Requests\Models\ApprovalRequest;
use App\Platform\Audit\AuditLogger;
use App\Platform\Exceptions\DomainRuleViolation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Edits a draft (ADR-0033). Only its requester, only while a draft, and
 * only if nobody saved it since the form was opened: the form sends the
 * lock version it was opened with, and a different one is refused rather
 * than silently overwriting the other change (optimistic locking).
 */
class UpdateDraft
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{title: string, details: string, amount?: string|float|int|null}  $input
     */
    public function handle(User $actor, ApprovalRequest $request, array $input, int $lockVersion): ApprovalRequest
    {
        return DB::transaction(function () use ($actor, $request, $input, $lockVersion): ApprovalRequest {
            $locked = ApprovalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->requester_id !== $actor->id) {
                throw new AuthorizationException;
            }

            if (! $locked->status->isEditable()) {
                throw new DomainRuleViolation(__('Only a draft can be changed.'), 'title');
            }

            if ($locked->lock_version !== $lockVersion) {
                throw new DomainRuleViolation(__('Someone else changed this request after you opened it. Reload to see their change.'), 'lock_version');
            }

            $locked->forceFill([
                'title' => trim($input['title']),
                'details' => trim($input['details']),
                'amount' => $input['amount'] ?? null,
            ]);
            $changes = AuditLogger::changesOf($locked, ['title', 'amount']);

            if (! $locked->isDirty()) {
                return $locked;
            }

            $locked->lock_version++;
            $locked->save();

            $this->audit->record('request.updated', $locked, [], $changes, actor: $actor);

            return $locked;
        });
    }
}
