/**
 * Numbers and dates in the reader's language (Arabic digits in Arabic).
 * Amounts arrive as exact decimal strings (numeric(12,2)) and are shown
 * with two decimals; no currency is assumed.
 */
export function formatters(locale: string) {
    const language = locale === 'ar' ? 'ar' : 'en';
    const amounts = new Intl.NumberFormat(language, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
    const dates = new Intl.DateTimeFormat(language, { dateStyle: 'medium' });
    const times = new Intl.DateTimeFormat(language, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

    return {
        amount: (value: string | null) =>
            value === null ? null : amounts.format(Number(value)),
        date: (value: string | null) =>
            value === null ? null : dates.format(new Date(value)),
        dateTime: (value: string | null) =>
            value === null ? null : times.format(new Date(value)),
    };
}
