import { Search } from 'lucide-react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { OPEN_SEARCH_EVENT } from '@/components/keyboard-shortcuts';
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useTranslations } from '@/hooks/use-translations';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

// Key names are the same in every language.
const SEARCH_KEYS = 'Ctrl K';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { t } = useTranslations();

    return (
        <header className="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex min-w-0 items-center gap-2">
                <SidebarTrigger className="-ms-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            {/* The search, for those who do not use Ctrl+K (#5). */}
            <Button
                variant="outline"
                size="sm"
                className="ms-auto text-muted-foreground"
                onClick={() =>
                    window.dispatchEvent(new Event(OPEN_SEARCH_EVENT))
                }
                data-test="open-search"
            >
                <Search aria-hidden />
                <span className="sr-only sm:not-sr-only">{t('Search')}</span>
                <kbd
                    aria-hidden
                    dir="ltr"
                    className="hidden rounded border bg-muted px-1.5 font-mono text-xs md:inline"
                >
                    {SEARCH_KEYS}
                </kbd>
            </Button>
        </header>
    );
}
