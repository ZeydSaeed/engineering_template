<?php

namespace App\Platform\Tables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * A table's search, filters, sort and page size, taken from the request or
 * a saved view and limited to what the definition declares (ADR-0026).
 * Anything else is dropped, never passed to the query.
 */
final readonly class TableState
{
    /**
     * @param  array<string, string>  $filters
     * @param  'asc'|'desc'  $direction
     */
    private function __construct(
        public string $search,
        public array $filters,
        public string $sort,
        public string $direction,
        public int $perPage,
    ) {}

    /**
     * @param  TableDefinition<covariant Model>  $table
     * @param  array<mixed>  $input  query parameters: search, filter[...], sort, direction, per_page
     */
    public static function from(TableDefinition $table, array $input): self
    {
        $search = is_string($input['search'] ?? null) ? mb_substr(trim($input['search']), 0, 100) : '';

        $filters = [];
        $requested = is_array($input['filter'] ?? null) ? $input['filter'] : [];

        foreach ($table->filters() as $filter => $allowed) {
            $value = $requested[$filter] ?? null;

            if (is_string($value) && in_array($value, $allowed, true)) {
                $filters[$filter] = $value;
            }
        }

        [$sort, $direction] = $table->defaultSort();

        if (is_string($input['sort'] ?? null) && in_array($input['sort'], $table->sortableColumns(), true)) {
            $sort = $input['sort'];
            $direction = ($input['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        }

        $perPage = (int) ($input['per_page'] ?? 0);

        if (! in_array($perPage, TableDefinition::PER_PAGE, true)) {
            $perPage = $table->defaultPerPage();
        }

        return new self($search, $filters, $sort, $direction, $perPage);
    }

    /**
     * Applies search, filters and sort, then one page.
     *
     * @template TModel of Model
     *
     * @param  TableDefinition<TModel>  $table
     * @param  Builder<TModel>  $query
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginate(TableDefinition $table, Builder $query): LengthAwarePaginator
    {
        if ($this->search !== '') {
            $table->applySearch($query, $this->search);
        }

        foreach ($this->filters as $filter => $value) {
            $table->applyFilter($query, $filter, $value);
        }

        $table->applySort($query, $this->sort, $this->direction);
        // A unique last key keeps pages stable when sorted values repeat.
        $query->orderBy($query->getModel()->getQualifiedKeyName());

        return $query->paginate($this->perPage)->withQueryString();
    }

    /**
     * @return array{search: string, filters: array<string, string>, sort: string, direction: 'asc'|'desc', per_page: int}
     */
    public function toArray(): array
    {
        return [
            'search' => $this->search,
            'filters' => $this->filters,
            'sort' => $this->sort,
            'direction' => $this->direction,
            'per_page' => $this->perPage,
        ];
    }
}
