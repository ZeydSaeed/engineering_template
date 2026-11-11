<?php

namespace App\Platform\Audit;

use Carbon\CarbonImmutable;
use LogicException;

/**
 * The keyed hash that links each audit record to the one before it
 * (ADR-0013). Changing, removing or reordering a record breaks every hash
 * after it, and without AUDIT_HMAC_KEY nobody can compute valid ones.
 *
 * PostgreSQL's jsonb reorders object keys, so arrays are hashed in a
 * canonical form (keys sorted at every depth).
 */
final class AuditChain
{
    /**
     * @param  array<string, mixed>  $fields  the record's columns except id, previous_hash and hash
     */
    public static function hash(?string $previousHash, array $fields): string
    {
        $key = (string) config('platform.audit.hmac_key');

        if ($key === '') {
            throw new LogicException('AUDIT_HMAC_KEY is not set; audit records cannot be written or verified.');
        }

        $fields['occurred_at'] = self::timestamp((string) $fields['occurred_at']);
        $payload = json_encode(
            [$previousHash, self::canonical($fields)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );

        return hash_hmac('sha256', $payload, $key);
    }

    /**
     * Microsecond UTC form, whatever time zone the database returns.
     */
    public static function timestamp(string $value): string
    {
        return CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonical($item);
            }
        }

        return $value;
    }
}
