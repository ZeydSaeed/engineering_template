import { Head, router } from '@inertiajs/react';
import { Bell, CheckCheck } from 'lucide-react';
import NotificationController from '@/actions/App/Http/Controllers/NotificationController';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
import { notificationText } from '@/components/notification-text';
import type { NotificationData } from '@/components/notification-text';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';
import { index as notificationsIndex } from '@/routes/notifications';

type Item = {
    id: string;
    data: NotificationData;
    read: boolean;
    created_at: string | null;
};

/**
 * A person's notifications (improvement #8, ADR-0029): newest first, unread
 * marked in words (not by colour alone), read one or all.
 */
export default function Notifications({ items }: { items: Item[] }) {
    const { t, locale } = useTranslations();
    const dates = new Intl.DateTimeFormat(locale === 'ar' ? 'ar' : 'en', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
    const unread = items.filter((item) => !item.read).length;
    const post = (url: string) =>
        router.post(url, {}, { preserveScroll: true, preserveState: true });

    return (
        <>
            <Head title={t('Notifications')} />
            <div className="flex max-w-3xl flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={t('Notifications')}
                        description={t(':count unread', { count: unread })}
                    />
                    {unread > 0 && (
                        <Button
                            variant="outline"
                            onClick={() =>
                                post(NotificationController.readAll.url())
                            }
                            data-test="read-all"
                        >
                            <CheckCheck aria-hidden />
                            {t('Mark all as read')}
                        </Button>
                    )}
                </div>

                {items.length === 0 ? (
                    <EmptyState
                        icon={Bell}
                        title={t('No notifications')}
                        description={t(
                            'Changes to your account will appear here.',
                        )}
                    />
                ) : (
                    <ul
                        className="divide-y rounded-xl border"
                        data-slot="notifications"
                    >
                        {items.map((item) => (
                            <li
                                key={item.id}
                                data-read={item.read}
                                className={cn(
                                    'flex flex-wrap items-center gap-3 px-4 py-3 text-sm',
                                    !item.read && 'bg-accent/40',
                                )}
                            >
                                {!item.read && (
                                    <span className="rounded bg-primary px-1.5 py-0.5 text-xs font-medium text-primary-foreground">
                                        {t('New')}
                                    </span>
                                )}
                                <span
                                    className={cn(
                                        'flex-1',
                                        !item.read && 'font-medium',
                                    )}
                                >
                                    {notificationText(t, item.data)}
                                </span>
                                {item.created_at && (
                                    <time
                                        dateTime={item.created_at}
                                        className="text-muted-foreground"
                                    >
                                        {dates.format(
                                            new Date(item.created_at),
                                        )}
                                    </time>
                                )}
                                {!item.read && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            post(
                                                NotificationController.read.url(
                                                    item.id,
                                                ),
                                            )
                                        }
                                    >
                                        {t('Mark as read')}
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

Notifications.layout = {
    breadcrumbs: [{ title: 'Notifications', href: notificationsIndex() }],
};
