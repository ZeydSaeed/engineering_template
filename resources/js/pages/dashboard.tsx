import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Bell,
    ClipboardList,
    FileText,
    History,
    Inbox,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import DashboardController from '@/actions/App/Http/Controllers/DashboardController';
import RequestController from '@/actions/App/Modules/Requests/Http/RequestController';
import ActivityTimeline from '@/components/activity-timeline';
import ColumnSettings from '@/components/data-table/column-settings';
import EmptyState from '@/components/empty-state';
import { notificationText } from '@/components/notification-text';
import type { NotificationData } from '@/components/notification-text';
import { useTranslations } from '@/hooks/use-translations';
import { dashboard } from '@/routes';
import { index as usersIndex } from '@/routes/admin/users';
import { index as documentsIndex } from '@/routes/documents';
import { index as notificationsIndex } from '@/routes/notifications';
import type { TimelineEntry } from '@/types/table';

type Layout = { order: string[]; hidden: string[]; available: string[] };

type Widgets = Partial<{
    notifications: {
        id: string;
        data: NotificationData;
        created_at: string | null;
    }[];
    activity: TimelineEntry[];
    documents: Record<string, number>;
    requests: {
        mine: Record<string, number>;
        to_decide: number | null;
    };
    people: {
        total: number;
        without_second_factor: number;
        administrators: number;
    };
}>;

function Widget({
    id,
    title,
    icon: Icon,
    link,
    children,
}: {
    id: string;
    title: string;
    icon: LucideIcon;
    link?: { href: string; label: string };
    children: ReactNode;
}) {
    return (
        <section
            aria-labelledby={`widget-${id}`}
            data-widget={id}
            className="flex flex-col gap-4 rounded-xl border p-4"
        >
            <div className="flex items-center gap-2">
                <Icon className="size-4 text-muted-foreground" aria-hidden />
                <h2
                    id={`widget-${id}`}
                    className="flex-1 text-base font-semibold"
                >
                    {title}
                </h2>
                {link && (
                    <Link
                        href={link.href}
                        className="text-sm text-primary underline-offset-4 hover:underline"
                    >
                        {link.label}
                    </Link>
                )}
            </div>
            {children}
        </section>
    );
}

function Figure({ value, label }: { value: number; label: string }) {
    return (
        // Label first for assistive technology; the number reads first.
        <div className="flex flex-col-reverse">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="text-2xl font-semibold tabular-nums">{value}</dd>
        </div>
    );
}

/**
 * The dashboard as widgets each person can show, hide and order
 * (improvement #1, ADR-0029). One column on phones, two on tablets, three
 * on wide screens (#11).
 */
export default function Dashboard({
    layout,
    widgets,
}: {
    layout: Layout;
    widgets: Widgets;
}) {
    const { t } = useTranslations();
    const { auth } = usePage().props;
    const visible = layout.order.filter((key) => !layout.hidden.includes(key));

    const titles: Record<string, string> = {
        notifications: t('Notifications'),
        activity: t('Your activity'),
        documents: t('Your documents'),
        requests: t('Requests'),
        people: t('People'),
    };

    const render = (key: string) => {
        switch (key) {
            case 'notifications':
                return (
                    <Widget
                        key={key}
                        id={key}
                        title={titles[key]}
                        icon={Bell}
                        link={{
                            href: notificationsIndex().url,
                            label: t('All notifications'),
                        }}
                    >
                        {(widgets.notifications ?? []).length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('No unread notifications.')}
                            </p>
                        ) : (
                            <ul className="space-y-2 text-sm">
                                {(widgets.notifications ?? []).map((item) => (
                                    <li key={item.id}>
                                        {notificationText(t, item.data)}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Widget>
                );
            case 'activity':
                return (
                    <Widget
                        key={key}
                        id={key}
                        title={titles[key]}
                        icon={History}
                    >
                        <ActivityTimeline entries={widgets.activity ?? []} />
                    </Widget>
                );
            case 'documents': {
                const counts = widgets.documents ?? {};

                return (
                    <Widget
                        key={key}
                        id={key}
                        title={titles[key]}
                        icon={FileText}
                        link={{
                            href: documentsIndex().url,
                            label: t('Documents'),
                        }}
                    >
                        <dl className="grid grid-cols-3 gap-2">
                            <Figure
                                value={counts.verified ?? 0}
                                label={t('Ready')}
                            />
                            <Figure
                                value={counts.pending ?? 0}
                                label={t('Being checked')}
                            />
                            <Figure
                                value={counts.rejected ?? 0}
                                label={t('Rejected')}
                            />
                        </dl>
                    </Widget>
                );
            }
            case 'requests': {
                const requests = widgets.requests;

                return (
                    <Widget
                        key={key}
                        id={key}
                        title={titles[key]}
                        icon={ClipboardList}
                        link={{
                            href: RequestController.index.url(),
                            label: t('Requests'),
                        }}
                    >
                        <dl className="grid grid-cols-3 gap-2">
                            {requests?.to_decide !== null &&
                                requests?.to_decide !== undefined && (
                                    <Figure
                                        value={requests.to_decide}
                                        label={t('Waiting for my decision')}
                                    />
                                )}
                            <Figure
                                value={requests?.mine.submitted ?? 0}
                                label={t('Mine waiting')}
                            />
                            <Figure
                                value={requests?.mine.draft ?? 0}
                                label={t('My drafts')}
                            />
                        </dl>
                    </Widget>
                );
            }
            case 'people': {
                const people = widgets.people;

                return (
                    <Widget
                        key={key}
                        id={key}
                        title={titles[key]}
                        icon={Users}
                        link={{ href: usersIndex().url, label: t('Users') }}
                    >
                        <dl className="grid grid-cols-3 gap-2">
                            <Figure
                                value={people?.total ?? 0}
                                label={t('In all')}
                            />
                            <Figure
                                value={people?.administrators ?? 0}
                                label={t('Administrators')}
                            />
                            <Figure
                                value={people?.without_second_factor ?? 0}
                                label={t('Without a second factor')}
                            />
                        </dl>
                    </Widget>
                );
            }
            default:
                return null;
        }
    };

    return (
        <>
            <Head title={t('Dashboard')} />
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-xl font-semibold">
                        {t('Welcome, :name', { name: auth.user.name })}
                    </h1>
                    <ColumnSettings
                        testId="customize-dashboard"
                        title={t('Customize dashboard')}
                        description={t(
                            'Choose the widgets to show and their order.',
                        )}
                        columns={layout.available.map((key) => ({
                            key,
                            header: titles[key] ?? key,
                            hideable: true,
                        }))}
                        prefs={{ order: layout.order, hidden: layout.hidden }}
                        onSave={(prefs) =>
                            router.put(
                                DashboardController.update.url(),
                                prefs,
                                {
                                    preserveScroll: true,
                                },
                            )
                        }
                    />
                </div>

                {visible.length === 0 ? (
                    <EmptyState
                        icon={Inbox}
                        title={t('Nothing here yet')}
                        description={t(
                            'All widgets are hidden; use "Customize dashboard" to show them.',
                        )}
                        className="flex-1"
                    />
                ) : (
                    <div
                        className="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
                        data-slot="widgets"
                    >
                        {visible.map(render)}
                    </div>
                )}
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
