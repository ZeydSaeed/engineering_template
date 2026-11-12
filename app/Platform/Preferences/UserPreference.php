<?php

namespace App\Platform\Preferences;

use Illuminate\Database\Eloquent\Model;

/**
 * One interface choice of one user, kept on the server so it follows the
 * person between devices (ADR-0026).
 *
 * @property int $id
 * @property int $user_id
 * @property string $key
 * @property array<mixed> $value
 */
class UserPreference extends Model
{
    protected $table = 'user_preferences';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /**
     * @return array<mixed>|null
     */
    public static function read(int $userId, string $key): ?array
    {
        $value = self::query()->where('user_id', $userId)->where('key', $key)->value('value');

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<mixed>  $value
     */
    public static function write(int $userId, string $key, array $value): void
    {
        self::query()->updateOrCreate(['user_id' => $userId, 'key' => $key], ['value' => $value]);
    }
}
