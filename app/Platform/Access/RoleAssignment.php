<?php

namespace App\Platform\Access;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One role held by one user. Rows change only through AssignRole and
 * RevokeRole, which lock, check and audit.
 *
 * @property int $id
 * @property int $user_id
 * @property string $role
 * @property int|null $granted_by
 * @property CarbonImmutable $created_at
 */
class RoleAssignment extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');

        return $this->belongsTo($model);
    }
}
