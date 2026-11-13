import { useDirection } from '@radix-ui/react-direction';
import { useEffect, useRef } from 'react';
import type { ReactNode } from 'react';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { currentOpener } from '@/lib/opener';
import { cn } from '@/lib/utils';

/**
 * A panel that slides in from the reading end: the left in Arabic, the
 * right in English (improvement #24). Like every dialog here (Radix), it
 * traps focus while open, closes on Esc or the close button, and returns
 * focus to the control that opened it. Text arrives translated.
 *
 * Controlled (`open` and `onOpenChange`) or with a `trigger`.
 */
export default function Drawer({
    title,
    description,
    trigger,
    open,
    onOpenChange,
    footer,
    className,
    children,
}: {
    title: string;
    description?: string;
    trigger?: ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    footer?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    const side = useDirection() === 'rtl' ? 'left' : 'right';
    // Opened from code (no trigger), Radix does not know the opener: keep
    // what had focus when it opened, to give focus back on close.
    const opener = useRef<HTMLElement | null>(null);

    useEffect(() => {
        if (open && !trigger) {
            opener.current = currentOpener();
        }
    }, [open, trigger]);

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            {trigger && <SheetTrigger asChild>{trigger}</SheetTrigger>}
            <SheetContent
                side={side}
                data-slot="drawer"
                onCloseAutoFocus={(event) => {
                    if (!trigger && opener.current?.isConnected) {
                        event.preventDefault();
                        opener.current.focus();
                    }
                }}
                className={cn('w-full gap-0 sm:max-w-md', className)}
            >
                <SheetHeader className="border-b">
                    <SheetTitle>{title}</SheetTitle>
                    {description && (
                        <SheetDescription>{description}</SheetDescription>
                    )}
                </SheetHeader>
                <div className="flex-1 overflow-y-auto p-4">{children}</div>
                {footer && (
                    <SheetFooter className="border-t">{footer}</SheetFooter>
                )}
            </SheetContent>
        </Sheet>
    );
}
