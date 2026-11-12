<?php

namespace App\Platform\Tables;

use Illuminate\Database\Eloquent\Model;

/**
 * A named table state a user saved for reuse (improvement #4): search,
 * filters, sort, page size and columns. Each user sees only their own.
 *
 * @property int $id
 * @property int $user_id
 * @property string $table
 * @property string $name
 * @property array{search?: string, filters?: array<string, string>, sort?: string, direction?: string, per_page?: int, columns?: array{order: list<string>, hidden: list<string>}} $state
 */
class SavedView extends Model
{
    public const MAX_PER_TABLE = 20;

    protected $table = 'saved_views';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['state' => 'array'];
    }
}
