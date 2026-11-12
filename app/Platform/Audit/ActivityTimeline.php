<?php

namespace App\Platform\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A record's history from the audit trail (improvement #9): what was done
 * to it and, for a person, what they did; newest first. Each part uses its
 * own index (subject_type + subject_id, actor_id + occurred_at), and actor
 * names come in one query.
 */
final class ActivityTimeline
{
    /** Metadata keys a timeline may show. */
    public const SHOWN_METADATA = ['role'];

    /**
     * @return list<array{id: int, action: string, occurred_at: string, actor: string|null, subject_is_record: bool, details: array<string, string>}>
     */
    public static function for(Model $record, bool $includeActions, int $limit = 20): array
    {
        $about = AuditEvent::query()
            ->where('subject_type', $record->getMorphClass())
            ->where('subject_id', (string) $record->getKey())
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $by = $includeActions
            ? AuditEvent::query()->where('actor_id', $record->getKey())->orderByDesc('id')->limit($limit)->get()
            : collect();

        $events = $about->merge($by)->unique('id')->sortByDesc('id')->take($limit)->values();

        $actorIds = $events->pluck('actor_id')->filter()->unique()->values()->all();
        $userModel = (string) config('auth.providers.users.model');

        if (! is_a($userModel, Model::class, true)) {
            throw new LogicException('auth.providers.users.model is not an Eloquent model.');
        }

        $names = $actorIds === [] ? [] : $userModel::query()->whereKey($actorIds)->pluck('name', 'id')->all();

        return array_values($events->map(fn (AuditEvent $event): array => [
            'id' => (int) $event->id,
            'action' => (string) $event->action,
            'occurred_at' => CarbonImmutable::parse($event->occurred_at)->utc()->toIso8601String(),
            'actor' => $event->actor_id === null ? null : (string) ($names[$event->actor_id] ?? '#'.$event->actor_id),
            'subject_is_record' => $event->subject_type === $record->getMorphClass() && $event->subject_id === (string) $record->getKey(),
            // Allow-listed metadata only; the rest may be technical or
            // personal (addresses, hashed identifiers).
            'details' => array_map(
                strval(...),
                array_filter(
                    array_intersect_key(is_array($event->metadata) ? $event->metadata : [], array_flip(self::SHOWN_METADATA)),
                    fn (mixed $value): bool => is_string($value) || is_int($value),
                ),
            ),
        ])->all());
    }
}
