<?php

use App\Modules\Requests\Domain\Decision;
use App\Modules\Requests\Domain\RequestStatus;

/*
| The request state machine (ADR-0033): every allowed move, and only those.
*/

it('allows exactly the moves of the machine', function () {
    $allowed = [];

    foreach (RequestStatus::cases() as $from) {
        foreach (RequestStatus::cases() as $to) {
            if ($from->canBecome($to)) {
                $allowed[] = "{$from->value}->{$to->value}";
            }
        }
    }

    expect($allowed)->toBe(['draft->submitted', 'submitted->approved', 'submitted->rejected']);
});

it('ends in approved or rejected, and only a draft is editable', function () {
    expect(array_map(fn (RequestStatus $s) => $s->isFinal(), RequestStatus::cases()))->toBe([false, false, true, true])
        ->and(array_map(fn (RequestStatus $s) => $s->isEditable(), RequestStatus::cases()))->toBe([true, false, false, false]);
});

it('leads each decision to its state; only a rejection needs a reason', function () {
    expect(Decision::Approve->outcome())->toBe(RequestStatus::Approved)
        ->and(Decision::Reject->outcome())->toBe(RequestStatus::Rejected)
        ->and(Decision::Approve->needsNote())->toBeFalse()
        ->and(Decision::Reject->needsNote())->toBeTrue();
});
