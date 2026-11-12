import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { roleLabel } from '@/components/activity-timeline';
import BarChart from '@/components/bar-chart';
import Heading from '@/components/heading';
import { useTranslations } from '@/hooks/use-translations';
import { reports } from '@/routes/admin';

/**
 * Reports for people who may read the audit trail (improvement #21,
 * ADR-0029): sign-ins per day over a chosen period, and people by role.
 */
export default function Reports({
    days,
    periods,
    signIns,
    roles,
}: {
    days: number;
    periods: number[];
    signIns: { day: string; count: number }[];
    roles: { role: string; count: number }[];
}) {
    const { t, locale } = useTranslations();
    const [chosen, setChosen] = useState(days);
    const language = locale === 'ar' ? 'ar' : 'en';
    const dayFormat = new Intl.DateTimeFormat(language, {
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    });
    const numbers = new Intl.NumberFormat(language);

    return (
        <>
            <Head title={t('Reports')} />
            <div className="flex flex-col gap-8 p-4">
                <Heading
                    title={t('Reports')}
                    description={t('Sign-ins and roles, from the audit trail.')}
                />

                <fieldset
                    className="flex flex-wrap items-center gap-3"
                    data-test="period"
                >
                    <legend className="mb-2 text-sm font-medium">
                        {t('Period')}
                    </legend>
                    {periods.map((period) => (
                        <label
                            key={period}
                            className="flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm has-[:checked]:border-primary has-[:checked]:bg-accent"
                        >
                            <input
                                type="radio"
                                name="days"
                                value={period}
                                checked={period === chosen}
                                onChange={() => {
                                    // Shown at once; the data follows.
                                    setChosen(period);
                                    router.get(
                                        reports.url(),
                                        { days: period },
                                        {
                                            preserveScroll: true,
                                            preserveState: true,
                                            replace: true,
                                        },
                                    );
                                }}
                                className="size-4 accent-primary"
                            />
                            {t('Last :days days', { days: period })}
                        </label>
                    ))}
                </fieldset>

                <BarChart
                    title={t('Sign-ins per day')}
                    description={t('Successful sign-ins, by day (UTC).')}
                    data={signIns.map((point) => ({
                        key: point.day,
                        label: dayFormat.format(
                            new Date(`${point.day}T00:00:00Z`),
                        ),
                        value: point.count,
                    }))}
                    format={(value) => numbers.format(value)}
                    labelEvery={days <= 7 ? 1 : days <= 30 ? 5 : 15}
                />

                <BarChart
                    title={t('People by role')}
                    description={t(
                        'Each role held; a person can have several.',
                    )}
                    data={roles.map((row) => ({
                        key: row.role,
                        label:
                            row.role === 'none'
                                ? t('No role')
                                : roleLabel(t, row.role),
                        value: row.count,
                    }))}
                    format={(value) => numbers.format(value)}
                />
            </div>
        </>
    );
}

Reports.layout = {
    breadcrumbs: [{ title: 'Reports', href: reports() }],
};
