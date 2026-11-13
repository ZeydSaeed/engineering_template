<?php

namespace App\Modules\Requests\Http;

use App\Modules\Requests\Domain\RequestStatus;
use App\Modules\Requests\Models\ApprovalRequest;
use App\Platform\Tables\TableDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * The request list (ADR-0026, ADR-0033). Which rows a person sees is not
 * the table's business: the controller limits the query first (their own,
 * or all for requests.view_all).
 *
 * @extends TableDefinition<ApprovalRequest>
 */
class RequestsTable extends TableDefinition
{
    public const NAME = 'requests';

    /** The Gate ability for the table's column and saved-view endpoints. */
    public const ABILITY = 'requests.list';

    public function name(): string
    {
        return self::NAME;
    }

    public function columns(): array
    {
        return [
            'title' => ['sortable' => true, 'hideable' => false],
            'requester' => ['sortable' => false, 'hideable' => true],
            'status' => ['sortable' => true, 'hideable' => false],
            'amount' => ['sortable' => true, 'hideable' => true],
            'submitted_at' => ['sortable' => true, 'hideable' => true],
            'created_at' => ['sortable' => true, 'hideable' => true],
        ];
    }

    public function filters(): array
    {
        return [
            'status' => RequestStatus::values(),
            // Waiting for a decision from someone other than the requester.
            'scope' => ['mine', 'to_decide'],
        ];
    }

    public function defaultSort(): array
    {
        return ['created_at', 'desc'];
    }

    public function applySearch(Builder $query, string $search): void
    {
        $this->whereContains($query, ['title'], $search);
    }

    public function applyFilter(Builder $query, string $filter, string $value): void
    {
        $viewer = (int) auth()->id();

        match ($filter) {
            'status' => $query->where('status', $value),
            'scope' => $value === 'mine'
                ? $query->where('requester_id', $viewer)
                : $query->where('status', RequestStatus::Submitted->value)->where('requester_id', '<>', $viewer),
            default => null,
        };
    }
}
