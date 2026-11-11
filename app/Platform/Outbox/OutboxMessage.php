<?php

namespace App\Platform\Outbox;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $uuid
 * @property string $topic
 * @property array<string, mixed> $payload
 * @property string $status pending | dispatched | dead
 * @property int $attempts
 * @property CarbonImmutable $available_at
 * @property CarbonImmutable|null $dispatched_at
 * @property string|null $last_error
 * @property CarbonImmutable $created_at
 */
class OutboxMessage extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'outbox_messages';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
