<?php

namespace App\Modules\Requests\Models;

use App\Models\User;
use App\Modules\Requests\Database\Factories\ApprovalRequestFactory;
use App\Modules\Requests\Domain\RequestStatus;
use App\Platform\Files\StoredFile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A request someone drafts, submits, and another person approves or
 * rejects (ADR-0033). Changed only by the module's actions, which keep the
 * state machine, the lock version and the audit trail; the bigint id stays
 * internal and the UUID is what URLs carry.
 *
 * @property int $id
 * @property string $uuid
 * @property int $requester_id
 * @property string $title
 * @property string $details
 * @property string|null $amount
 * @property RequestStatus $status
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[UseFactory(ApprovalRequestFactory::class)]
class ApprovalRequest extends Model
{
    /** @use HasFactory<ApprovalRequestFactory> */
    use HasFactory;

    use HasUuids;

    /** The morph alias in audit records, files and notifications. */
    public const MORPH = 'approval_request';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RequestStatus::class,
            'amount' => 'decimal:2',
            'lock_version' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * The UUID, not the primary key, is generated (the id stays bigint).
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return MorphMany<StoredFile, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(StoredFile::class, 'attachable');
    }
}
