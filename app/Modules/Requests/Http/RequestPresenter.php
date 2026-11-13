<?php

namespace App\Modules\Requests\Http;

use App\Access\Permission;
use App\Models\User;
use App\Modules\Requests\Models\ApprovalRequest;
use App\Platform\Audit\ActivityTimeline;
use App\Platform\Files\StoredFile;
use Illuminate\Support\Facades\Gate;

/**
 * A request as the interface receives it: plain values, and what this
 * person may do with it, decided by RequestPolicy (ADR-0033).
 */
final class RequestPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function row(ApprovalRequest $request): array
    {
        return [
            'uuid' => $request->uuid,
            'title' => $request->title,
            'requester' => $request->requester?->name,
            'status' => $request->status->value,
            'amount' => $request->amount,
            'submitted_at' => $request->submitted_at?->toIso8601String(),
            'created_at' => $request->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function full(ApprovalRequest $request, User $viewer): array
    {
        $gate = Gate::forUser($viewer);

        return [
            ...self::row($request),
            'details' => $request->details,
            'lock_version' => $request->lock_version,
            'is_mine' => $request->requester_id === $viewer->id,
            'decider' => $request->decider?->name,
            'decided_at' => $request->decided_at?->toIso8601String(),
            'decision_note' => $request->decision_note,
            'attachments' => $request->attachments
                ->sortByDesc('id')
                ->map(fn (StoredFile $file): array => [
                    'uuid' => $file->uuid,
                    'name' => $file->original_name,
                    'size' => $file->size_bytes,
                    'status' => $file->status,
                ])
                ->values()
                ->all(),
            // What was done to this request, from the audit trail. Not
            // includeActions: that adds what a *person* did, matched by id,
            // and would mix in the user whose id equals this request's.
            'timeline' => ActivityTimeline::for($request, includeActions: false, limit: 30),
            'can' => [
                'update' => $gate->allows('update', $request),
                'submit' => $gate->allows('submit', $request),
                'decide' => $gate->allows('decide', $request),
                'see_all' => $viewer->hasPermission(Permission::RequestsViewAll),
            ],
        ];
    }
}
