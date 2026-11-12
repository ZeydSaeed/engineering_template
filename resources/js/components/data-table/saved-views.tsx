import { router } from '@inertiajs/react';
import { Bookmark, Trash2 } from 'lucide-react';
import { useState } from 'react';
import ConfirmAction from '@/components/confirm-action';
import FormField from '@/components/form-field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { useTranslations } from '@/hooks/use-translations';
import type { ColumnPrefs, SavedViewItem, TableState } from '@/types/table';
import type { RouteFormDefinition } from '@/wayfinder';

/**
 * Named views of a table (improvement #4): apply one, save the current
 * search, filters, sort, size and columns under a name (the same name
 * replaces it), or delete one. Views are the person's own (ADR-0026).
 */
export default function SavedViews({
    views,
    state,
    columns,
    saveUrl,
    deleteForm,
    onApply,
}: {
    views: SavedViewItem[];
    state: TableState;
    columns: ColumnPrefs;
    saveUrl: string;
    deleteForm: (view: SavedViewItem) => RouteFormDefinition<'post'>;
    onApply: (view: SavedViewItem) => void;
}) {
    const { t } = useTranslations();
    const [saving, setSaving] = useState(false);
    const [managing, setManaging] = useState(false);
    const [name, setName] = useState('');
    const [error, setError] = useState<string>();
    const [processing, setProcessing] = useState(false);

    const save = () => {
        router.post(
            saveUrl,
            { name, state, columns },
            {
                only: ['views'],
                preserveState: true,
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (errors) => setError(errors.name ?? errors.state),
                onSuccess: () => {
                    setSaving(false);
                    setName('');
                    setError(undefined);
                },
            },
        );
    };

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="outline" data-test="saved-views">
                        <Bookmark aria-hidden />
                        {t('Views')}
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-56">
                    <DropdownMenuLabel>{t('Saved views')}</DropdownMenuLabel>
                    {views.length === 0 && (
                        <p className="px-2 py-1.5 text-sm text-muted-foreground">
                            {t('No saved views yet')}
                        </p>
                    )}
                    {views.map((view) => (
                        <DropdownMenuItem
                            key={view.id}
                            onSelect={() => onApply(view)}
                            data-view={view.name}
                        >
                            {view.name}
                        </DropdownMenuItem>
                    ))}
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        onSelect={() => setSaving(true)}
                        data-test="save-view"
                    >
                        {t('Save current view')}
                    </DropdownMenuItem>
                    {views.length > 0 && (
                        <DropdownMenuItem onSelect={() => setManaging(true)}>
                            {t('Manage views')}
                        </DropdownMenuItem>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>

            <Dialog open={saving} onOpenChange={setSaving}>
                <DialogContent>
                    <DialogTitle>{t('Save current view')}</DialogTitle>
                    <DialogDescription>
                        {t(
                            'Keeps the search, filters, sort, page size and columns under a name.',
                        )}
                    </DialogDescription>
                    <form
                        className="space-y-6"
                        onSubmit={(event) => {
                            event.preventDefault();
                            save();
                        }}
                    >
                        <FormField
                            name="view_name"
                            label={t('Name')}
                            error={error}
                            required
                        >
                            {(control) => (
                                <Input
                                    {...control}
                                    value={name}
                                    maxLength={80}
                                    onChange={(event) =>
                                        setName(event.target.value)
                                    }
                                />
                            )}
                        </FormField>
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button type="button" variant="secondary">
                                    {t('Cancel')}
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                disabled={processing || name.trim() === ''}
                                data-test="confirm-save-view"
                            >
                                {t('Save')}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Closes by itself when the last view is deleted. */}
            <Dialog
                open={managing && views.length > 0}
                onOpenChange={setManaging}
            >
                <DialogContent data-slot="manage-views">
                    <DialogTitle>{t('Manage views')}</DialogTitle>
                    <DialogDescription>
                        {t('Delete the views you no longer need.')}
                    </DialogDescription>
                    <ul className="divide-y rounded-lg border">
                        {views.map((view) => (
                            <li
                                key={view.id}
                                className="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                            >
                                {view.name}
                                <ConfirmAction
                                    trigger={
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={t('Delete view :name', {
                                                name: view.name,
                                            })}
                                        >
                                            <Trash2 aria-hidden />
                                        </Button>
                                    }
                                    title={t('Delete this view?')}
                                    description={t(
                                        'The view ":name" will be removed. The data is not affected.',
                                        { name: view.name },
                                    )}
                                    confirmLabel={t('Delete')}
                                    form={deleteForm(view)}
                                />
                            </li>
                        ))}
                    </ul>
                </DialogContent>
            </Dialog>
        </>
    );
}
