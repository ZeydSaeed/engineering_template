import { CircleAlert, CircleCheck, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import type { BulkResult } from '@/types/table';

/**
 * The bar shown while rows are selected (improvement #23): how many, what
 * can be done with them, and a way to clear the selection. The count is
 * announced when it changes.
 */
export function BulkActionBar({
    count,
    onClear,
    children,
}: {
    count: number;
    onClear: () => void;
    children: ReactNode;
}) {
    const { t } = useTranslations();

    if (count === 0) {
        return null;
    }

    return (
        <div
            role="region"
            aria-label={t('Selected rows')}
            data-slot="bulk-bar"
            className="flex flex-wrap items-center gap-3 rounded-xl border bg-accent px-4 py-2 text-sm text-accent-foreground"
        >
            <span aria-live="polite" className="font-medium">
                {t(':count selected', { count })}
            </span>
            <div className="flex flex-wrap gap-2">{children}</div>
            <Button
                variant="ghost"
                size="sm"
                className="ms-auto"
                onClick={onClear}
            >
                <X aria-hidden />
                {t('Clear selection')}
            </Button>
        </div>
    );
}

/**
 * What a bulk action did: how many succeeded and, for each that did not,
 * which row and why. Announced politely; dismissible.
 */
export function BulkResultPanel({
    result,
    onDismiss,
}: {
    result: BulkResult;
    onDismiss: () => void;
}) {
    const { t } = useTranslations();
    const failed = result.failed.length;

    return (
        <div
            role="status"
            data-slot="bulk-result"
            className="space-y-2 rounded-xl border p-4 text-sm"
        >
            <div className="flex items-start gap-2">
                {failed === 0 ? (
                    <CircleCheck
                        className="mt-0.5 size-4 text-success"
                        aria-hidden
                    />
                ) : (
                    <CircleAlert
                        className="mt-0.5 size-4 text-destructive-foreground"
                        aria-hidden
                    />
                )}
                <p className="flex-1 font-medium">
                    {t(':done updated, :failed not updated.', {
                        done: result.succeeded.length,
                        failed,
                    })}
                </p>
                <Button
                    variant="ghost"
                    size="icon"
                    onClick={onDismiss}
                    aria-label={t('Dismiss')}
                >
                    <X aria-hidden />
                </Button>
            </div>
            {failed > 0 && (
                <ul className="list-disc space-y-1 ps-10 text-muted-foreground">
                    {result.failed.map((failure) => (
                        <li key={failure.id}>
                            <span className="font-medium text-foreground">
                                {failure.name ?? `#${failure.id}`}
                            </span>
                            {': '}
                            {failure.reason}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
