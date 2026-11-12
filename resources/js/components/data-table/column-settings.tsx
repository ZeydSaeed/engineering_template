import { ArrowDown, ArrowUp, Columns3 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { useTranslations } from '@/hooks/use-translations';
import type { ColumnPrefs } from '@/types/table';

/**
 * Which columns show, and in what order (improvement #3). Reordering uses
 * buttons, so it works from the keyboard and with screen readers; the
 * choice is saved on the server for the person (ADR-0026).
 */
export default function ColumnSettings({
    columns,
    prefs,
    onSave,
    title,
    description,
    testId = 'column-settings',
}: {
    columns: { key: string; header: string; hideable: boolean }[];
    prefs: ColumnPrefs;
    onSave: (prefs: ColumnPrefs) => void;
    // Defaults to the table's columns; other ordered lists (dashboard
    // widgets) give their own words.
    title?: string;
    description?: string;
    testId?: string;
}) {
    const { t } = useTranslations();
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState<ColumnPrefs>(prefs);
    const byKey = new Map(columns.map((column) => [column.key, column]));

    const move = (index: number, by: -1 | 1) => {
        const order = [...draft.order];
        [order[index], order[index + by]] = [order[index + by], order[index]];
        setDraft({ ...draft, order });
    };

    const toggle = (key: string) =>
        setDraft({
            ...draft,
            hidden: draft.hidden.includes(key)
                ? draft.hidden.filter((other) => other !== key)
                : [...draft.hidden, key],
        });

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (next) {
                    setDraft(prefs);
                }
            }}
        >
            <DialogTrigger asChild>
                <Button variant="outline" data-test={testId}>
                    <Columns3 aria-hidden />
                    {title ?? t('Columns')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>{title ?? t('Columns')}</DialogTitle>
                <DialogDescription>
                    {description ??
                        t('Choose the columns to show and their order.')}
                </DialogDescription>

                <ol className="divide-y rounded-lg border">
                    {draft.order.map((key, index) => {
                        const column = byKey.get(key);

                        if (!column) {
                            return null;
                        }

                        const checkboxId = `${testId}-${key}`;

                        return (
                            <li
                                key={key}
                                className="flex items-center gap-3 px-3 py-2"
                            >
                                <Checkbox
                                    id={checkboxId}
                                    checked={!draft.hidden.includes(key)}
                                    disabled={!column.hideable}
                                    onCheckedChange={() => toggle(key)}
                                />
                                <label
                                    htmlFor={checkboxId}
                                    className="flex-1 text-sm"
                                >
                                    {column.header}
                                    {!column.hideable && (
                                        <span className="ms-2 text-xs text-muted-foreground">
                                            {t('(always shown)')}
                                        </span>
                                    )}
                                </label>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    disabled={index === 0}
                                    onClick={() => move(index, -1)}
                                    aria-label={t('Move :column up', {
                                        column: column.header,
                                    })}
                                >
                                    <ArrowUp aria-hidden />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    disabled={index === draft.order.length - 1}
                                    onClick={() => move(index, 1)}
                                    aria-label={t('Move :column down', {
                                        column: column.header,
                                    })}
                                >
                                    <ArrowDown aria-hidden />
                                </Button>
                            </li>
                        );
                    })}
                </ol>

                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">{t('Cancel')}</Button>
                    </DialogClose>
                    <Button
                        data-test="save-columns"
                        onClick={() => {
                            onSave(draft);
                            setOpen(false);
                        }}
                    >
                        {t('Save')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
