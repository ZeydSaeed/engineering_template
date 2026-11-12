import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import { useId } from 'react';
import type { ReactNode } from 'react';
import EmptyState from '@/components/empty-state';
import { Checkbox } from '@/components/ui/checkbox';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';
import type { ColumnPrefs, TableState } from '@/types/table';

export type ColumnDef<Row> = {
    key: string;
    header: string;
    cell: (row: Row) => ReactNode;
    sortable?: boolean;
    className?: string;
};

/**
 * A server-side table (improvement #2): the server sorts, filters and
 * pages; this renders one page in the person's column order (#3), with
 * sortable headers (aria-sort), optional row selection (#23) and a row
 * action (#16). On narrow screens the table scrolls inside its own region,
 * which the keyboard can reach. Texts arrive translated.
 */
export default function DataTable<Row>({
    caption,
    columns,
    prefs,
    rows,
    rowId,
    rowLabel,
    state,
    onSort,
    selected,
    onSelectedChange,
    rowAction,
    busy = false,
    filtered = false,
    onClearFilters,
}: {
    caption: string;
    columns: ColumnDef<Row>[];
    prefs: ColumnPrefs;
    rows: Row[];
    rowId: (row: Row) => number;
    rowLabel: (row: Row) => string;
    state: TableState;
    onSort: (column: string) => void;
    selected?: number[];
    onSelectedChange?: (ids: number[]) => void;
    rowAction?: (row: Row) => ReactNode;
    busy?: boolean;
    filtered?: boolean;
    onClearFilters?: () => void;
}) {
    const { t } = useTranslations();
    const captionId = useId();
    const byKey = new Map(columns.map((column) => [column.key, column]));
    const visible = prefs.order
        .filter((key) => !prefs.hidden.includes(key))
        .map((key) => byKey.get(key))
        .filter((column): column is ColumnDef<Row> => column !== undefined);

    const selectable = selected !== undefined && onSelectedChange !== undefined;
    const pageIds = rows.map(rowId);
    const selectedOnPage = pageIds.filter((id) => selected?.includes(id));
    const allSelected =
        pageIds.length > 0 && selectedOnPage.length === pageIds.length;

    const toggleAll = () => {
        if (!onSelectedChange || !selected) {
            return;
        }

        onSelectedChange(
            allSelected
                ? selected.filter((id) => !pageIds.includes(id))
                : [...new Set([...selected, ...pageIds])],
        );
    };

    const toggle = (id: number) => {
        if (!onSelectedChange || !selected) {
            return;
        }

        onSelectedChange(
            selected.includes(id)
                ? selected.filter((other) => other !== id)
                : [...selected, id],
        );
    };

    if (rows.length === 0) {
        return filtered ? (
            <EmptyState
                kind="no-results"
                title={t('No results')}
                description={t('Nothing matches these filters.')}
                action={
                    onClearFilters && (
                        <button
                            type="button"
                            onClick={onClearFilters}
                            className="text-sm font-medium text-primary underline underline-offset-4"
                        >
                            {t('Clear filters')}
                        </button>
                    )
                }
            />
        ) : (
            <EmptyState title={t('Nothing here yet')} />
        );
    }

    return (
        <div
            role="region"
            aria-labelledby={captionId}
            // Scrollable on narrow screens, so reachable by keyboard.
            tabIndex={0}
            data-slot="data-table"
            className="overflow-x-auto rounded-xl border focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
        >
            <table
                aria-busy={busy || undefined}
                className={cn(
                    'w-full min-w-[40rem] text-sm transition-opacity',
                    busy && 'opacity-60',
                )}
            >
                <caption id={captionId} className="sr-only">
                    {caption}
                </caption>
                <thead className="bg-muted/50 text-start">
                    <tr className="border-b">
                        {selectable && (
                            <th scope="col" className="w-10 px-4 py-3">
                                <Checkbox
                                    aria-label={t('Select all on this page')}
                                    checked={
                                        allSelected
                                            ? true
                                            : selectedOnPage.length > 0
                                              ? 'indeterminate'
                                              : false
                                    }
                                    onCheckedChange={toggleAll}
                                />
                            </th>
                        )}
                        {visible.map((column) => {
                            const active = state.sort === column.key;
                            const sortValue = active
                                ? state.direction === 'asc'
                                    ? 'ascending'
                                    : 'descending'
                                : undefined;

                            return (
                                <th
                                    key={column.key}
                                    scope="col"
                                    aria-sort={
                                        column.sortable
                                            ? (sortValue ?? 'none')
                                            : undefined
                                    }
                                    className={cn(
                                        'px-4 py-3 text-start font-medium whitespace-nowrap',
                                        column.className,
                                    )}
                                >
                                    {column.sortable ? (
                                        <button
                                            type="button"
                                            onClick={() => onSort(column.key)}
                                            data-sort={column.key}
                                            className="inline-flex items-center gap-1 rounded-sm hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                        >
                                            {column.header}
                                            {active ? (
                                                state.direction === 'asc' ? (
                                                    <ArrowUp
                                                        className="size-4"
                                                        aria-hidden
                                                    />
                                                ) : (
                                                    <ArrowDown
                                                        className="size-4"
                                                        aria-hidden
                                                    />
                                                )
                                            ) : (
                                                <ArrowUpDown
                                                    className="size-4 text-muted-foreground"
                                                    aria-hidden
                                                />
                                            )}
                                        </button>
                                    ) : (
                                        column.header
                                    )}
                                </th>
                            );
                        })}
                        {rowAction && (
                            <th scope="col" className="px-4 py-3">
                                <span className="sr-only">{t('Actions')}</span>
                            </th>
                        )}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => {
                        const id = rowId(row);
                        const isSelected = selected?.includes(id) ?? false;

                        return (
                            <tr
                                key={id}
                                data-row={id}
                                data-selected={isSelected || undefined}
                                className={cn(
                                    'border-b last:border-b-0',
                                    isSelected && 'bg-accent/60',
                                )}
                            >
                                {selectable && (
                                    <td className="px-4 py-3">
                                        <Checkbox
                                            aria-label={t('Select :name', {
                                                name: rowLabel(row),
                                            })}
                                            checked={isSelected}
                                            onCheckedChange={() => toggle(id)}
                                        />
                                    </td>
                                )}
                                {visible.map((column) => (
                                    <td
                                        key={column.key}
                                        className={cn(
                                            'px-4 py-3',
                                            column.className,
                                        )}
                                    >
                                        {column.cell(row)}
                                    </td>
                                ))}
                                {rowAction && (
                                    <td className="px-4 py-3 text-end">
                                        {rowAction(row)}
                                    </td>
                                )}
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
