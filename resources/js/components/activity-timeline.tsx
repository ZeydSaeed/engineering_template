import { History } from 'lucide-react';
import EmptyState from '@/components/empty-state';
import { useTranslations } from '@/hooks/use-translations';
import type { TimelineEntry } from '@/types/table';

type Translate = ReturnType<typeof useTranslations>['t'];

/** A role as people read it. */
export function roleLabel(t: Translate, role: string): string {
    switch (role) {
        case 'administrator':
            return t('Administrator');
        case 'auditor':
            return t('Auditor');
        case 'approver':
            return t('Approver');
        default:
            return role;
    }
}

/**
 * Audit actions as people read them; others show their code. Literal t()
 * calls, so TranslationsTest checks each has an Arabic translation.
 */
function actionLabel(t: Translate, entry: TimelineEntry): string {
    const role = entry.details.role ? roleLabel(t, entry.details.role) : '';

    switch (entry.action) {
        case 'access.role_assigned':
            return t('Role granted: :role', { role });
        case 'access.role_revoked':
            return t('Role removed: :role', { role });
        case 'auth.login':
            return t('Signed in');
        case 'auth.logout':
            return t('Signed out');
        case 'auth.failed':
            return t('Failed sign-in');
        case 'auth.lockout':
            return t('Locked out after failed sign-ins');
        case 'auth.password_changed':
            return t('Password changed');
        case 'auth.password_reset':
            return t('Password reset');
        case 'auth.email_verified':
            return t('Email verified');
        case 'auth.two_factor_confirmed':
            return t('Two-factor authentication enabled');
        case 'auth.two_factor_disabled':
            return t('Two-factor authentication disabled');
        case 'auth.two_factor_failed':
            return t('Wrong two-factor code');
        case 'auth.recovery_code_used':
            return t('Recovery code used');
        case 'auth.recovery_codes_generated':
            return t('New recovery codes created');
        case 'file.uploaded':
            return t('File uploaded');
        case 'file.rejected':
            return t('File rejected');
        case 'request.created':
            return t('Request created');
        case 'request.updated':
            return t('Request changed');
        case 'request.submitted':
            return t('Request submitted');
        case 'request.approved':
            return t('Request approved');
        case 'request.rejected':
            return t('Request rejected');
        case 'user.invited':
            return role
                ? t('Invited as :role', { role })
                : t('Invited to the application');
        default:
            return entry.action;
    }
}

/**
 * A record's history from the audit trail (improvement #9), newest first:
 * what happened, who did it and when, in the reader's language and
 * calendar. A <time> element carries the exact moment.
 */
export default function ActivityTimeline({
    entries,
}: {
    entries: TimelineEntry[];
}) {
    const { t, locale } = useTranslations();
    const format = new Intl.DateTimeFormat(locale === 'ar' ? 'ar' : 'en', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

    if (entries.length === 0) {
        return (
            <EmptyState
                icon={History}
                title={t('No activity yet')}
                headingLevel={3}
                bordered={false}
            />
        );
    }

    return (
        <ol data-slot="timeline" className="relative space-y-4 border-s ps-5">
            {entries.map((entry) => (
                <li key={entry.id} className="relative">
                    <span
                        aria-hidden
                        className="absolute -start-[1.65rem] top-1.5 size-2.5 rounded-full border-2 border-background bg-primary"
                    />
                    <p className="text-sm font-medium">
                        {actionLabel(t, entry)}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        <time dateTime={entry.occurred_at}>
                            {format.format(new Date(entry.occurred_at))}
                        </time>
                        {entry.actor &&
                            (entry.subject_is_record
                                ? ` · ${t('by :name', { name: entry.actor })}`
                                : ` · ${t('by this person')}`)}
                    </p>
                </li>
            ))}
        </ol>
    );
}
