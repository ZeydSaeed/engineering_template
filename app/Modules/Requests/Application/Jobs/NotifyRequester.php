<?php

namespace App\Modules\Requests\Application\Jobs;

use App\Modules\Requests\Application\Notifications\RequestNotification;
use App\Modules\Requests\Domain\RequestStatus;
use App\Modules\Requests\Models\ApprovalRequest;
use App\Platform\Outbox\OutboxJob;

/**
 * requests.decided: tells the requester the decision (ADR-0033). Runs
 * once per message (OutboxJob).
 */
class NotifyRequester extends OutboxJob
{
    protected function process(array $payload): void
    {
        $request = ApprovalRequest::query()->with(['requester', 'decider'])->where('uuid', (string) ($payload['request'] ?? ''))->first();

        if ($request === null || $request->requester === null || ! $request->status->isFinal()) {
            return;
        }

        $request->requester->notify(new RequestNotification(
            $request->status === RequestStatus::Approved ? RequestNotification::APPROVED : RequestNotification::REJECTED,
            $request->uuid,
            $request->title,
            (string) $request->decider?->name,
        ));
    }
}
