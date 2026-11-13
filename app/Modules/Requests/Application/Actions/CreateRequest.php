<?php

namespace App\Modules\Requests\Application\Actions;

use App\Models\User;
use App\Modules\Requests\Domain\RequestStatus;
use App\Modules\Requests\Models\ApprovalRequest;
use App\Platform\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Starts a request as a draft owned by the person writing it (ADR-0033).
 * Anyone signed in may ask; the input is validated by the HTTP layer.
 */
class CreateRequest
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{title: string, details: string, amount?: string|float|int|null}  $input
     */
    public function handle(User $requester, array $input): ApprovalRequest
    {
        return DB::transaction(function () use ($requester, $input): ApprovalRequest {
            $request = new ApprovalRequest;
            $request->forceFill([
                'requester_id' => $requester->id,
                'title' => trim($input['title']),
                'details' => trim($input['details']),
                'amount' => $input['amount'] ?? null,
                'status' => RequestStatus::Draft,
            ])->save();

            $this->audit->record('request.created', $request, ['title' => $request->title], actor: $requester);

            return $request;
        });
    }
}
