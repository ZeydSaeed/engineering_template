import { CircleHelp } from 'lucide-react';
import { useId, useState } from 'react';
import type { ReactNode } from 'react';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';

/**
 * Short help next to a field or section (improvement #27). A disclosure,
 * not a hover tooltip: it opens with a click, a tap or the keyboard, stays
 * until closed, and its text is read in place. The topic names the button
 * ("Help: Density"). Text arrives translated.
 */
export default function HelpTip({
    topic,
    children,
    className,
}: {
    topic: string;
    children: ReactNode;
    className?: string;
}) {
    const { t } = useTranslations();
    const id = useId();
    const [open, setOpen] = useState(false);

    return (
        <div className={cn('space-y-2', className)} data-slot="help-tip">
            <button
                type="button"
                aria-expanded={open}
                aria-controls={id}
                onClick={() => setOpen(!open)}
                className="inline-flex items-center gap-1 rounded-sm text-sm text-muted-foreground hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
            >
                <CircleHelp className="size-4" aria-hidden />
                {t('Help: :topic', { topic })}
            </button>
            <div
                id={id}
                hidden={!open}
                className="rounded-lg border bg-muted/50 px-3 py-2 text-sm text-foreground"
            >
                {children}
            </div>
        </div>
    );
}
