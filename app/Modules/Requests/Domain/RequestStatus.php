<?php

namespace App\Modules\Requests\Domain;

/**
 * Where a request is in its life (ADR-0033):
 *
 *   draft --submit--> submitted --approve--> approved
 *                               --reject---> rejected
 *
 * Only these moves exist; approved and rejected are final. The database
 * holds the same rule (CHECK constraints), so no code path can write a
 * state the machine does not allow.
 */
enum RequestStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function canBecome(self $next): bool
    {
        return in_array($next, $this->next(), true);
    }

    /**
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted => [self::Approved, self::Rejected],
            self::Approved, self::Rejected => [],
        };
    }

    public function isFinal(): bool
    {
        return $this->next() === [];
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }
}
