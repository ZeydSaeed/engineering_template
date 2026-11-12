<?php

namespace App\Platform\Tables;

use App\Platform\Preferences\UserPreference;
use Illuminate\Database\Eloquent\Model;

/**
 * A user's column order and hidden columns for one table (improvement #3),
 * always reconciled with the definition: unknown columns are dropped, new
 * columns appear in their default place, and only hideable columns can be
 * hidden.
 */
final class TableColumns
{
    /**
     * @param  TableDefinition<covariant Model>  $table
     * @return array{order: list<string>, hidden: list<string>}
     */
    public static function for(int $userId, TableDefinition $table): array
    {
        $saved = UserPreference::read($userId, self::key($table)) ?? [];

        return self::normalise($table, $saved['order'] ?? [], $saved['hidden'] ?? []);
    }

    /**
     * @param  TableDefinition<covariant Model>  $table
     * @param  array<mixed>  $order
     * @param  array<mixed>  $hidden
     * @return array{order: list<string>, hidden: list<string>}
     */
    public static function save(int $userId, TableDefinition $table, array $order, array $hidden): array
    {
        $columns = self::normalise($table, $order, $hidden);
        UserPreference::write($userId, self::key($table), $columns);

        return $columns;
    }

    /**
     * @param  TableDefinition<covariant Model>  $table
     * @param  array<mixed>  $order
     * @param  array<mixed>  $hidden
     * @return array{order: list<string>, hidden: list<string>}
     */
    public static function normalise(TableDefinition $table, array $order, array $hidden): array
    {
        $known = array_keys($table->columns());
        $ordered = array_values(array_unique(array_filter($order, fn (mixed $key): bool => is_string($key) && in_array($key, $known, true))));

        foreach ($known as $key) {
            if (! in_array($key, $ordered, true)) {
                $ordered[] = $key;
            }
        }

        $hideable = $table->hideableColumns();
        $hidden = array_values(array_unique(array_filter($hidden, fn (mixed $key): bool => is_string($key) && in_array($key, $hideable, true))));

        return ['order' => $ordered, 'hidden' => $hidden];
    }

    /**
     * @param  TableDefinition<covariant Model>  $table
     */
    private static function key(TableDefinition $table): string
    {
        return 'table:'.$table->name().':columns';
    }
}
