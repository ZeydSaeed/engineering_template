import { Head, usePage } from '@inertiajs/react';
import { Inbox } from 'lucide-react';
import EmptyState from '@/components/empty-state';
import { useTranslations } from '@/hooks/use-translations';
import { dashboard } from '@/routes';

export default function Dashboard() {
    const { t } = useTranslations();
    const { auth } = usePage().props;

    return (
        <>
            <Head title={t('Dashboard')} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <h1 className="text-xl font-semibold">
                    {t('Welcome, :name', { name: auth.user.name })}
                </h1>
                <EmptyState
                    icon={Inbox}
                    title={t('Nothing here yet')}
                    description={t(
                        'The modules you add will show their work on this page.',
                    )}
                    className="flex-1"
                />
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
