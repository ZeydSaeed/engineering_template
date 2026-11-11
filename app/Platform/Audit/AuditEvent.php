<?php

namespace App\Platform\Audit;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One append-only audit record (ADR-0013). Written only by AuditLogger;
 * PostgreSQL rejects updates, deletes and truncation with a trigger and by
 * revoking those rights from the runtime role.
 *
 * @property int $id
 * @property string $occurred_at
 * @property int|null $actor_id
 * @property string|null $guard
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $request_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<string, mixed>|null $changes
 * @property array<string, mixed>|null $metadata
 * @property string|null $previous_hash
 * @property string $hash
 */
class AuditEvent extends Model
{
    public $timestamps = false;

    protected $table = 'audit_events';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actor_id' => 'integer',
            'changes' => 'array',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Audit records are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Audit records are append-only.'));
    }
}
