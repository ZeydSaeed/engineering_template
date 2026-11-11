<?php

namespace App\Platform\Audit;

use App\Platform\Logging\MaskSensitiveData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes the append-only, hash-chained audit trail (ADR-0013).
 *
 * Metadata and changes must be non-sensitive; known secret keys are masked
 * anyway. Never pass passwords, tokens, one-time codes or file contents.
 */
class AuditLogger
{
    /** Serialises writers so each record links to the latest one. */
    private const LOCK_KEY = 7_041_022_513;

    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, array{from: mixed, to: mixed}>  $changes  allow-listed fields only (see changesOf)
     */
    public function record(
        string $action,
        ?Model $subject = null,
        array $metadata = [],
        array $changes = [],
        ?Authenticatable $actor = null,
    ): AuditEvent {
        $actor ??= Auth::user();
        $mask = MaskSensitiveData::fromConfig();

        $fields = [
            'occurred_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP'),
            'actor_id' => $actor === null ? null : (int) $actor->getAuthIdentifier(),
            'guard' => $actor === null ? null : $this->guardOf($actor),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject === null ? null : (string) $subject->getKey(),
            'request_id' => Context::get('request_id'),
            'ip_address' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 250, ''),
            'changes' => $changes === [] ? null : $mask->mask($changes),
            'metadata' => $metadata === [] ? null : $mask->mask($metadata),
        ];

        return DB::transaction(function () use ($fields): AuditEvent {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(?)', [self::LOCK_KEY]);
            }

            $previous = AuditEvent::query()->orderByDesc('id')->value('hash');
            $previous = is_string($previous) ? $previous : null;

            $event = new AuditEvent;
            $event->forceFill([
                ...$fields,
                'previous_hash' => $previous,
                'hash' => AuditChain::hash($previous, $fields),
            ])->save();

            return $event;
        });
    }

    /**
     * Before/after values of the allow-listed attributes that changed on an
     * unsaved model. Call it before save().
     *
     * @param  list<string>  $attributes
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function changesOf(Model $model, array $attributes): array
    {
        $changes = [];

        foreach ($attributes as $attribute) {
            if ($model->isDirty($attribute)) {
                $changes[$attribute] = ['from' => $model->getRawOriginal($attribute), 'to' => $model->getAttributes()[$attribute] ?? null];
            }
        }

        return $changes;
    }

    private function guardOf(Authenticatable $actor): ?string
    {
        foreach (array_keys((array) config('auth.guards')) as $guard) {
            if (Auth::guard((string) $guard)->user() === $actor) {
                return (string) $guard;
            }
        }

        return null;
    }
}
