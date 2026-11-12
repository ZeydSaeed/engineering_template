// Server-side tables (ADR-0026): the shapes App\Platform\Tables sends.

export type SortDirection = 'asc' | 'desc';

export type TableState = {
    search: string;
    filters: Record<string, string>;
    sort: string;
    direction: SortDirection;
    per_page: number;
};

export type ColumnPrefs = {
    order: string[];
    hidden: string[];
};

export type PageMeta = {
    page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

export type SavedViewItem = {
    id: number;
    name: string;
    state: Partial<TableState> & { columns?: ColumnPrefs };
};

export type BulkResult = {
    succeeded: number[];
    failed: { id: number; name: string | null; reason: string }[];
};

export type TimelineEntry = {
    id: number;
    action: string;
    occurred_at: string;
    actor: string | null;
    subject_is_record: boolean;
    details: Record<string, string>;
};
