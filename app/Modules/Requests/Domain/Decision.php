<?php

namespace App\Modules\Requests\Domain;

/**
 * An approver's decision on a submitted request, and the state it leads to.
 */
enum Decision: string
{
    case Approve = 'approve';
    case Reject = 'reject';

    public function outcome(): RequestStatus
    {
        return match ($this) {
            self::Approve => RequestStatus::Approved,
            self::Reject => RequestStatus::Rejected,
        };
    }

    /**
     * A rejection must say why; an approval may.
     */
    public function needsNote(): bool
    {
        return $this === self::Reject;
    }
}
