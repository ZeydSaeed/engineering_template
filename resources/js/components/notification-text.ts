import RequestController from '@/actions/App/Modules/Requests/Http/RequestController';
import { roleLabel } from '@/components/activity-timeline';
import type { useTranslations } from '@/hooks/use-translations';

type Translate = ReturnType<typeof useTranslations>['t'];

export type NotificationData = Record<string, string>;

/**
 * A notification as a sentence in the reader's language (ADR-0029): the
 * server stores the facts, the interface writes the words. Literal t()
 * calls, so each has an Arabic translation (TranslationsTest).
 */
export function notificationText(t: Translate, data: NotificationData): string {
    switch (data.kind) {
        case 'role_granted':
            return t(':by granted you the role :role.', {
                by: data.by ?? '',
                role: roleLabel(t, data.role ?? ''),
            });
        case 'role_removed':
            return t(':by removed your role :role.', {
                by: data.by ?? '',
                role: roleLabel(t, data.role ?? ''),
            });
        case 'request_submitted':
            return t(':by submitted “:title” for a decision.', {
                by: data.by ?? '',
                title: data.title ?? '',
            });
        case 'request_approved':
            return t(':by approved your request “:title”.', {
                by: data.by ?? '',
                title: data.title ?? '',
            });
        case 'request_rejected':
            return t(':by rejected your request “:title”.', {
                by: data.by ?? '',
                title: data.title ?? '',
            });
        default:
            return t('Something changed in your account.');
    }
}

/** Where a notification leads, if anywhere (the request it is about). */
export function notificationHref(data: NotificationData): string | null {
    return data.request && data.kind?.startsWith('request_')
        ? RequestController.show.url(data.request)
        : null;
}
