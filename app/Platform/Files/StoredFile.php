<?php

namespace App\Platform\Files;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $uuid
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $extension
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property string $status pending | verified | rejected
 * @property string|null $rejection_reason
 * @property CarbonImmutable|null $scanned_at
 * @property string|null $attachable_type
 * @property int|null $attachable_id
 * @property int|null $uploaded_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class StoredFile extends Model
{
    public const PENDING = 'pending';

    public const VERIFIED = 'verified';

    public const REJECTED = 'rejected';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'scanned_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isVerified(): bool
    {
        return $this->status === self::VERIFIED;
    }
}
