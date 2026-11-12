import { Skeleton } from '@/components/ui/skeleton';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';

/**
 * Placeholders shaped like the content that is loading (improvement #12).
 * Each announces "Loading" once to screen readers (role="status") and hides
 * its shapes from them. The pulse stops for people who prefer reduced
 * motion (app.css).
 */
function Loading({
    label,
    className,
    children,
}: {
    label?: string;
    className?: string;
    children: React.ReactNode;
}) {
    const { t } = useTranslations();

    return (
        <div
            role="status"
            aria-live="polite"
            data-slot="skeleton-group"
            className={className}
        >
            <span className="sr-only">{label ?? t('Loading')}</span>
            <div aria-hidden className="contents">
                {children}
            </div>
        </div>
    );
}

export function SkeletonText({
    lines = 3,
    label,
    className,
}: {
    lines?: number;
    label?: string;
    className?: string;
}) {
    return (
        <Loading label={label} className={cn('space-y-2', className)}>
            {Array.from({ length: lines }, (_, line) => (
                <Skeleton
                    key={line}
                    className={cn(
                        'h-4',
                        line === lines - 1 ? 'w-2/3' : 'w-full',
                    )}
                />
            ))}
        </Loading>
    );
}

export function SkeletonCard({
    label,
    className,
}: {
    label?: string;
    className?: string;
}) {
    return (
        <Loading
            label={label}
            className={cn('space-y-3 rounded-xl border p-4', className)}
        >
            <Skeleton className="h-5 w-1/3" />
            <Skeleton className="h-4 w-full" />
            <Skeleton className="h-4 w-5/6" />
        </Loading>
    );
}

export function SkeletonTable({
    rows = 5,
    columns = 4,
    label,
    className,
}: {
    rows?: number;
    columns?: number;
    label?: string;
    className?: string;
}) {
    return (
        <Loading
            label={label}
            className={cn('overflow-hidden rounded-xl border', className)}
        >
            {Array.from({ length: rows + 1 }, (_, row) => (
                <div
                    key={row}
                    className={cn(
                        'grid gap-4 border-b px-4 py-3 last:border-b-0',
                        row === 0 && 'bg-muted/50',
                    )}
                    style={{
                        gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))`,
                    }}
                >
                    {Array.from({ length: columns }, (_, column) => (
                        <Skeleton
                            key={column}
                            className={cn(
                                'h-4',
                                row === 0 ? 'w-1/2' : 'w-full',
                            )}
                        />
                    ))}
                </div>
            ))}
        </Loading>
    );
}
