<?php

namespace App\Platform\Tables;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * What a server-side table allows (ADR-0026): its columns (which can be
 * sorted or hidden), its filters with their permitted values, whether it can
 * be searched, and its page sizes. TableState accepts only what is declared
 * here, so request input never reaches a query unchecked.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 */
abstract class TableDefinition
{
    public const PER_PAGE = [10, 25, 50, 100];

    /** The name used in URLs, preferences and saved views. */
    abstract public function name(): string;

    /**
     * Column key => [sortable, hideable]. The order is the default order.
     *
     * @return array<string, array{sortable: bool, hideable: bool}>
     */
    abstract public function columns(): array;

    /**
     * Filter key => permitted values.
     *
     * @return array<string, list<string>>
     */
    public function filters(): array
    {
        return [];
    }

    /**
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    abstract public function defaultSort(): array;

    public function defaultPerPage(): int
    {
        return 25;
    }

    /**
     * @param  Builder<TModel>  $query
     */
    abstract public function applySearch(Builder $query, string $search): void;

    /**
     * @param  Builder<TModel>  $query
     */
    abstract public function applyFilter(Builder $query, string $filter, string $value): void;

    /**
     * @param  Builder<TModel>  $query
     * @param  'asc'|'desc'  $direction
     */
    public function applySort(Builder $query, string $column, string $direction): void
    {
        $query->orderBy($column, $direction);
    }

    /**
     * Rows where any of the columns contains the text, ignoring case, with
     * % and _ taken literally. The same SQL on PostgreSQL and SQLite: an
     * explicit ESCAPE, since SQLite's LIKE has no default escape character.
     * Column names come from the definition, never from the request.
     *
     * @param  Builder<TModel>  $query
     * @param  non-empty-list<literal-string>  $columns  plain column names written in code
     */
    protected function whereContains(Builder $query, array $columns, string $search): void
    {
        $pattern = '%'.mb_strtolower(str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search)).'%';

        $query->where(function (Builder $where) use ($columns, $pattern): void {
            foreach ($columns as $column) {
                if (preg_match('/^[a-z_][a-z0-9_]*$/', $column) !== 1) {
                    throw new InvalidArgumentException("Not a plain column name: {$column}");
                }

                $where->orWhereRaw('lower('.$column.") like ? escape '!'", [$pattern]);
            }
        });
    }

    /**
     * @return list<string>
     */
    public function sortableColumns(): array
    {
        return array_keys(array_filter($this->columns(), fn (array $column): bool => $column['sortable']));
    }

    /**
     * @return list<string>
     */
    public function hideableColumns(): array
    {
        return array_keys(array_filter($this->columns(), fn (array $column): bool => $column['hideable']));
    }
}
