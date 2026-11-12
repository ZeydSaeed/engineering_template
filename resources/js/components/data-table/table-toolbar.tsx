import { Search, X } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTranslations } from '@/hooks/use-translations';

export type FilterDef = {
    key: string;
    label: string;
    options: { value: string; label: string }[];
};

const ALL = '__all__';

/**
 * Search (sent after a short pause in typing) and filters for a
 * server-side table, plus room for the column and view menus.
 */
export default function TableToolbar({
    search,
    filters,
    values,
    onSearch,
    onFilter,
    onClear,
    children,
}: {
    search: string;
    filters: FilterDef[];
    values: Record<string, string>;
    onSearch: (search: string) => void;
    onFilter: (key: string, value: string | null) => void;
    onClear: () => void;
    children?: ReactNode;
}) {
    const { t } = useTranslations();
    const searchId = useId();
    const [text, setText] = useState(search);

    // Follows navigation (a saved view, clearing filters).
    useEffect(() => setText(search), [search]);

    useEffect(() => {
        if (text === search) {
            return;
        }

        const timer = window.setTimeout(() => onSearch(text), 350);

        return () => window.clearTimeout(timer);
    }, [text, search, onSearch]);

    const active = search !== '' || Object.keys(values).length > 0;

    return (
        <div className="flex flex-wrap items-end gap-3">
            <div className="grid gap-1.5">
                <label htmlFor={searchId} className="text-sm font-medium">
                    {t('Search')}
                </label>
                <div className="relative">
                    <Search
                        className="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden
                    />
                    <Input
                        id={searchId}
                        type="search"
                        value={text}
                        onChange={(event) => setText(event.target.value)}
                        className="w-56 ps-8"
                        data-test="table-search"
                        // "/" moves focus here (keyboard shortcuts, #17).
                        data-shortcut-search
                    />
                </div>
            </div>

            {filters.map((filter) => (
                <div key={filter.key} className="grid gap-1.5">
                    <span
                        id={`${searchId}-${filter.key}`}
                        className="text-sm font-medium"
                    >
                        {filter.label}
                    </span>
                    <Select
                        value={values[filter.key] ?? ALL}
                        onValueChange={(value) =>
                            onFilter(filter.key, value === ALL ? null : value)
                        }
                    >
                        <SelectTrigger
                            className="w-44"
                            aria-labelledby={`${searchId}-${filter.key}`}
                            data-test={`filter-${filter.key}`}
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>{t('All')}</SelectItem>
                            {filter.options.map((option) => (
                                <SelectItem
                                    key={option.value}
                                    value={option.value}
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            ))}

            {active && (
                <Button variant="ghost" onClick={onClear}>
                    <X aria-hidden />
                    {t('Clear filters')}
                </Button>
            )}

            <div className="ms-auto flex items-center gap-2">{children}</div>
        </div>
    );
}
