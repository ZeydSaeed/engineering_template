import { useDirection } from '@radix-ui/react-direction';
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

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            {trigger && <SheetTrigger asChild>{trigger}</SheetTrigger>}
            <SheetContent
                side={side}
                data-slot="drawer"
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
