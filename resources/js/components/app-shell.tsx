import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { SidebarProvider } from '@/components/ui/sidebar';
import type { AppVariant } from '@/types';

type Props = {
    children: ReactNode;
    variant?: AppVariant;
};

// Below this width (tablets) the expanded sidebar takes too much of the
// content, so it starts as icons until the person chooses (#11).
const WIDE_ENOUGH = '(min-width: 1280px)';

export function AppShell({ children, variant = 'sidebar' }: Props) {
    const { sidebarOpen, sidebarChosen } = usePage().props;

    if (variant === 'header') {
        return (
            <div className="flex min-h-screen w-full flex-col">{children}</div>
        );
    }

    const defaultOpen = sidebarChosen
        ? sidebarOpen
        : window.matchMedia(WIDE_ENOUGH).matches;

    return (
        <SidebarProvider defaultOpen={defaultOpen}>{children}</SidebarProvider>
    );
}
