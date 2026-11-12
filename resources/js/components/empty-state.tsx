import { CircleAlert, SearchX } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type EmptyStateKind = 'empty' | 'no-results' | 'error';

const KIND_ICON: Record<Exclude<EmptyStateKind, 'empty'>, LucideIcon> = {
    'no-results': SearchX,
    error: CircleAlert,
};

/**
 * What a list or page shows when it has nothing to show (improvement #13),
 * in three kinds:
 *  - empty: nothing exists yet; say what would be here and the next step;
 *  - no-results: a search or filter matched nothing; offer to clear it;
 *  - error: loading failed; offer to retry (announced to screen readers).
 * Text arrives translated.
 */
export default function EmptyState({
    kind = 'empty',
    icon,
    title,
    description,
    action,
    headingLevel = 2,
    bordered = true,
    className,
}: {
    kind?: EmptyStateKind;
    icon?: LucideIcon;
    title: string;
    description?: string;
    action?: ReactNode;
    headingLevel?: 2 | 3 | 4;
    bordered?: boolean;
    className?: string;
}) {
    const Icon = icon ?? (kind === 'empty' ? null : KIND_ICON[kind]);
    const Heading = `h${headingLevel}` as const;

    return (
        <div
            role={kind === 'error' ? 'alert' : undefined}
            data-slot="empty-state"
            data-kind={kind}
            className={cn(
                'flex flex-col items-center justify-center gap-3 px-6 py-12 text-center',
                bordered && 'rounded-xl border border-dashed border-border',
                className,
            )}
        >
            {Icon && (
                <div
                    className={cn(
                        'flex size-12 items-center justify-center rounded-full',
                        kind === 'error' ? 'bg-destructive/10' : 'bg-muted',
                    )}
                >
                    <Icon
                        className={cn(
                            'size-6',
                            kind === 'error'
                                ? 'text-destructive-foreground'
                                : 'text-muted-foreground',
                        )}
                        aria-hidden
                    />
                </div>
            )}
            <Heading className="text-base font-semibold">{title}</Heading>
            {description && (
                <p className="max-w-sm text-sm text-muted-foreground">
                    {description}
                </p>
            )}
            {action && <div className="mt-2">{action}</div>}
        </div>
    );
}
