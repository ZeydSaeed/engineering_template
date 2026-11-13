<?php

namespace App\Modules\Requests\Http;

use App\Access\Permission;
use App\Models\User;
use App\Modules\Requests\Domain\RequestStatus;
use App\Modules\Requests\Models\ApprovalRequest;

/**
 * Who may see and act on a request (ADR-0033). The actions check the same
 * rules again under the row lock; the policy decides what the interface
 * offers and turns direct requests for anything else into 403 or 404.
 */
class RequestPolicy
{
    /** Everyone signed in may ask for something and see their own. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ApprovalRequest $request): bool
    {
        return $request->requester_id === $user->id || $user->hasPermission(Permission::RequestsViewAll);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ApprovalRequest $request): bool
    {
        return $request->requester_id === $user->id && $request->status->isEditable();
    }

    public function submit(User $user, ApprovalRequest $request): bool
    {
        return $this->update($user, $request);
    }

    public function decide(User $user, ApprovalRequest $request): bool
    {
        return $request->status === RequestStatus::Submitted
            && $request->requester_id !== $user->id
            && $user->hasPermission(Permission::RequestsDecide);
    }
}
