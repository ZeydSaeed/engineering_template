import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useId } from 'react';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTranslations } from '@/hooks/use-translations';
import type { PageMeta } from '@/types/table';

const PAGE_SIZES = [10, 25, 50, 100];

/**
 * Where the table is (rows x-y of n, page p of q), page size, and
 * previous/next. Arrows mirror in right-to-left languages.
 */
export default function TablePagination({
    meta,
    perPage,
    onPage,
    onPerPage,
}: {
    meta: PageMeta;
    perPage: number;
    onPage: (page: number) => void;
    onPerPage: (size: number) => void;
}) {
    const { t } = useTranslations();
    const sizeId = useId();

    return (
        <nav
            aria-label={t('Pagination')}
            className="flex flex-wrap items-center justify-between gap-3 text-sm"
        >
            <p aria-live="polite" className="text-muted-foreground">
                {meta.total === 0
                    ? t('No rows')
                    : t('Showing :from to :to of :total', {
                          from: meta.from ?? 0,
                          to: meta.to ?? 0,
                          total: meta.total,
                      })}
            </p>

            <div className="flex items-center gap-2">
                <label htmlFor={sizeId} className="text-muted-foreground">
                    {t('Rows per page')}
                </label>
                <Select
                    value={String(perPage)}
                    onValueChange={(value) => onPerPage(Number(value))}
                >
                    <SelectTrigger id={sizeId} className="w-20">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {PAGE_SIZES.map((size) => (
                            <SelectItem key={size} value={String(size)}>
                                {size}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <Button
                    variant="outline"
                    size="icon"
                    disabled={meta.page <= 1}
                    onClick={() => onPage(meta.page - 1)}
                    aria-label={t('Previous page')}
                    data-test="previous-page"
                >
                    <ChevronLeft className="rtl:-scale-x-100" aria-hidden />
                </Button>
                <span className="whitespace-nowrap">
                    {t('Page :page of :last', {
                        page: meta.page,
                        last: meta.last_page,
                    })}
                </span>
                <Button
                    variant="outline"
                    size="icon"
                    disabled={meta.page >= meta.last_page}
                    onClick={() => onPage(meta.page + 1)}
                    aria-label={t('Next page')}
                    data-test="next-page"
                >
                    <ChevronRight className="rtl:-scale-x-100" aria-hidden />
                </Button>
            </div>
        </nav>
    );
}
