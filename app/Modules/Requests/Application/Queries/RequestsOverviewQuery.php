<?php

namespace App\Modules\Requests\Application\Queries;

use App\Modules\Requests\Contracts\RequestsOverview;
use App\Modules\Requests\Domain\RequestStatus;
use App\Modules\Requests\Models\ApprovalRequest;

/**
 * Two indexed counts (ADR-0033): the person's requests by status
 * (requester_id index) and the submitted ones waiting for someone else's
 * decision (status, submitted_at index).
 */
class RequestsOverviewQuery implements RequestsOverview
{
    public function for(int $userId, bool $mayDecide): array
    {
        $mine = ApprovalRequest::query()
            ->where('requester_id', $userId)
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();

        return [
            'mine' => array_merge(array_fill_keys(RequestStatus::values(), 0), $mine),
            'to_decide' => $mayDecide
                ? ApprovalRequest::query()
                    ->where('status', RequestStatus::Submitted->value)
                    ->where('requester_id', '<>', $userId)
                    ->count()
                : null,
        ];
    }
}
