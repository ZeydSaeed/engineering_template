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
        default:
            return t('Something changed in your account.');
    }
}
