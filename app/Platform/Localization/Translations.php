<?php

namespace App\Platform\Localization;

/**
 * The interface strings for one locale, as sent to the browser: the
 * contents of lang/<locale>.json, keyed by the English text (ADR-0024).
 * English needs no file: a missing key shows the key itself.
 */
final class Translations
{
    /** @var array<string, array<string, string>> */
    private static array $loaded = [];

    /**
     * @return array<string, string>
     */
    public static function for(string $locale): array
    {
        if (isset(self::$loaded[$locale])) {
            return self::$loaded[$locale];
        }

        $file = lang_path("{$locale}.json");
        $strings = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];

        return self::$loaded[$locale] = is_array($strings)
            ? array_filter($strings, fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value), ARRAY_FILTER_USE_BOTH)
            : [];
    }
}
