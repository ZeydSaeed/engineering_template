<?php

namespace App\Modules\Requests\Application\Jobs;

use App\Access\Permission;
use App\Models\User;
use App\Modules\Requests\Application\Notifications\RequestNotification;
use App\Modules\Requests\Models\ApprovalRequest;
use App\Platform\Access\AccessCatalog;
use App\Platform\Access\RoleAssignment;
use App\Platform\Outbox\OutboxJob;
use Illuminate\Support\Facades\Notification;

/**
 * requests.submitted: tells everyone who may decide, except the requester
 * (ADR-0033). Runs once per message (OutboxJob): the notifications are
 * written in the same transaction as the processed mark.
 */
class NotifyApprovers extends OutboxJob
{
    protected function process(array $payload): void
    {
        $request = ApprovalRequest::query()->with('requester')->where('uuid', (string) ($payload['request'] ?? ''))->first();

        if ($request === null) {
            return;
        }

        $roles = array_values(array_map(
            fn ($role): string => (string) $role->value,
            array_filter(
                AccessCatalog::roleEnum()::cases(),
                fn ($role): bool => in_array(Permission::RequestsDecide, $role->permissions(), true),
            ),
        ));

        $approvers = User::query()
            ->whereIn('id', RoleAssignment::query()->select('user_id')->whereIn('role', $roles))
            ->whereKeyNot($request->requester_id)
            ->get();

        Notification::send($approvers, new RequestNotification(
            RequestNotification::SUBMITTED,
            $request->uuid,
            $request->title,
            (string) $request->requester?->name,
        ));
    }
}
