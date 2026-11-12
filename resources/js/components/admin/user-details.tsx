import { ShieldCheck } from 'lucide-react';
import ActivityTimeline, { roleLabel } from '@/components/activity-timeline';
import { useTranslations } from '@/hooks/use-translations';
import type { TimelineEntry } from '@/types/table';

export type SelectedUser = {
    id: number;
    name: string;
    email: string;
    email_verified: boolean;
    two_factor: boolean;
    created_at: string | null;
    roles: { role: string; since: string }[];
    is_viewer: boolean;
    timeline: TimelineEntry[] | null;
};

/**
 * One person's details and history, shown in the users page's side panel
 * (#16, #9). Loaded only when the panel first opens, to keep the list page
 * small (ADR-0026).
 */
export default function UserDetails({ user }: { user: SelectedUser }) {
    const { t, locale } = useTranslations();
    const dates = new Intl.DateTimeFormat(locale === 'ar' ? 'ar' : 'en', {
        dateStyle: 'medium',
    });

    return (
        <div className="space-y-6 text-sm" data-slot="user-details">
            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2">
                <dt className="text-muted-foreground">{t('Email verified')}</dt>
                <dd>{user.email_verified ? t('Yes') : t('No')}</dd>
                <dt className="text-muted-foreground">{t('Two-factor')}</dt>
                <dd>{user.two_factor ? t('Enabled') : t('Not enabled')}</dd>
                <dt className="text-muted-foreground">{t('Joined')}</dt>
                <dd>
                    {user.created_at && (
                        <time dateTime={user.created_at}>
                            {dates.format(new Date(user.created_at))}
                        </time>
                    )}
                </dd>
            </dl>

            <section aria-labelledby="user-roles" className="space-y-2">
                <h3 id="user-roles" className="font-semibold">
                    {t('Roles')}
                </h3>
                {user.roles.length === 0 ? (
                    <p className="text-muted-foreground">{t('None')}</p>
                ) : (
                    <ul className="space-y-1">
                        {user.roles.map((role) => (
                            <li
                                key={role.role}
                                className="flex items-center gap-2"
                            >
                                <ShieldCheck
                                    className="size-4 text-primary"
                                    aria-hidden
                                />
                                {roleLabel(t, role.role)}
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {user.timeline !== null && (
                <section aria-labelledby="user-activity" className="space-y-3">
                    <h3 id="user-activity" className="font-semibold">
                        {t('Activity')}
                    </h3>
                    <ActivityTimeline entries={user.timeline} />
                </section>
            )}
        </div>
    );
}
