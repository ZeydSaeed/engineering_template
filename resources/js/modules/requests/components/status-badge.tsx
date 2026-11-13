import { CircleCheck, CircleDashed, CircleX, Send } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { useTranslations } from '@/hooks/use-translations';
import type { RequestStatus } from '../types';

type Translate = ReturnType<typeof useTranslations>['t'];

/** A status as people read it. */
export function statusLabel(t: Translate, status: string): string {
    switch (status) {
        case 'draft':
            return t('Draft');
        case 'submitted':
            return t('Waiting for a decision');
        case 'approved':
            return t('Approved');
        case 'rejected':
            return t('Rejected');
        default:
            return status;
    }
}

/**
 * A request's state: a word and an icon, never colour alone (WCAG 1.4.1).
 */
export default function StatusBadge({ status }: { status: RequestStatus }) {
    const { t } = useTranslations();
    const icon = {
        draft: CircleDashed,
        submitted: Send,
        approved: CircleCheck,
        rejected: CircleX,
    }[status];
    const Icon = icon;

    return (
        <Badge
            variant={
                status === 'approved'
                    ? 'default'
                    : status === 'rejected'
                      ? 'destructive'
                      : status === 'submitted'
                        ? 'secondary'
                        : 'outline'
            }
            data-slot="request-status"
            data-status={status}
        >
            <Icon aria-hidden />
            {statusLabel(t, status)}
        </Badge>
    );
}
