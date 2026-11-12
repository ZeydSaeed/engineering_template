import { usePage } from '@inertiajs/react';
import { useCallback } from 'react';

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
 * Switches the interface language: the choice is kept in the "locale"
 * cookie (read by the SetLocale middleware) and the page reloads, so the
 * server renders the new direction and strings.
 */
export function changeLocale(locale: string): void {
    document.cookie = `locale=${encodeURIComponent(locale)};path=/;max-age=${365 * 24 * 60 * 60};SameSite=Lax`;
    window.location.reload();
}
