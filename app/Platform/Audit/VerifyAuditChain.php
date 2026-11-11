<?php

namespace App\Platform\Audit;

/**
 * Recomputes the audit chain from the first record (ADR-0013).
 */
class VerifyAuditChain
{
    /**
     * @return array{checked: int, broken_at: int|null, reason: string|null}
     */
    public function run(): array
    {
        $previous = null;
        $checked = 0;

        foreach (AuditEvent::query()->lazyById(500) as $event) {
            $checked++;

            if ($event->previous_hash !== $previous) {
                return ['checked' => $checked, 'broken_at' => $event->id, 'reason' => 'previous_hash does not match the record before it'];
            }

            $fields = [
                'occurred_at' => $event->occurred_at,
                'actor_id' => $event->actor_id,
                'guard' => $event->guard,
                'action' => $event->action,
                'subject_type' => $event->subject_type,
                'subject_id' => $event->subject_id,
                'request_id' => $event->request_id,
                'ip_address' => $event->ip_address,
                'user_agent' => $event->user_agent,
                'changes' => $event->changes,
                'metadata' => $event->metadata,
            ];

            if (! hash_equals(AuditChain::hash($previous, $fields), $event->hash)) {
                return ['checked' => $checked, 'broken_at' => $event->id, 'reason' => 'hash does not match the record contents'];
            }

            $previous = $event->hash;
        }

        return ['checked' => $checked, 'broken_at' => null, 'reason' => null];
    }
}
