import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { TableState } from '@/types/table';

export type TableVisit = Partial<TableState> & { page?: number };

/**
 * Moves a server-side table to a new search, filter, sort, size or page
 * (ADR-0026): the state lives in the URL, so a view can be bookmarked or
 * shared, and only the table's own props are reloaded.
 */
export function useTableNavigation(state: TableState, only: string[]) {
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        const stopStart = router.on('start', () => setBusy(true));
        const stopFinish = router.on('finish', () => setBusy(false));

        return () => {
            stopStart();
            stopFinish();
        };
    }, []);

    const visit = (next: TableVisit, extra: Record<string, string> = {}) => {
        const merged = { ...state, ...next };
        const query: Record<string, string | number> = {
            sort: merged.sort,
            direction: merged.direction,
            per_page: merged.per_page,
            ...extra,
        };

        if (merged.search) {
            query.search = merged.search;
        }

        for (const [key, value] of Object.entries(merged.filters ?? {})) {
            if (value) {
                query[`filter[${key}]`] = value;
            }
        }

        // A new search, filter or size starts again at page 1.
        if (next.page && next.page > 1) {
            query.page = next.page;
        }

        router.get(window.location.pathname, query, {
            only,
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    return { visit, busy };
}
