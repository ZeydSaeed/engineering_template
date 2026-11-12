import { usePage } from '@inertiajs/react';
import { useCallback } from 'react';
import { savePreferences } from '@/hooks/use-preferences';

export type Direction = 'ltr' | 'rtl';

type Replacements = Record<string, string | number>;

/**
 * Interface strings in the current language (ADR-0024). Keys are the English
 * text, as in lang/<locale>.json; a missing key shows the key itself, so
 * English needs no file. ":name" placeholders are replaced from the second
 * argument.
 *
 *   const { t } = useTranslations();
 *   t('Welcome, :name', { name: user.name })
 */
export function useTranslations() {
    const { translations, locale, locales, direction } = usePage().props;

    const t = useCallback(
        (key: string, replacements: Replacements = {}): string =>
            Object.entries(replacements).reduce(
                (text, [name, value]) =>
                    text.replaceAll(`:${name}`, String(value)),
                translations[key] ?? key,
            ),
        [translations],
    );

    return { t, locale, locales, direction };
}

/**
 * Switches the interface language and reloads, so the server renders the
 * new direction and strings. Kept in the "locale" cookie, and on the server
 * for a signed-in person (`save`, ADR-0028), whose saved choice wins.
 */
export function changeLocale(locale: string, save = false): void {
    document.cookie = `locale=${encodeURIComponent(locale)};path=/;max-age=${365 * 24 * 60 * 60};SameSite=Lax`;

    if (save) {
        savePreferences({ locale }, () => window.location.reload());

        return;
    }

    window.location.reload();
}
