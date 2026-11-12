import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTranslations } from '@/hooks/use-translations';

/**
 * The list of keyboard shortcuts (#17), opened with "?". Loaded when first
 * opened, to keep the code every page loads small (bundle budget).
 */
export default function ShortcutsHelp({
    open,
    onOpenChange,
    shortcuts,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    shortcuts: boolean;
}) {
    const { t } = useTranslations();

    const rows: [string, string][] = [
        ['Ctrl K / ⌘ K', t('Search pages and people')],
        ...(shortcuts
            ? ([
                  ['?', t('Show these shortcuts')],
                  ['/', t("Go to the page's search field")],
                  ['g d', t('Go to the dashboard')],
                  ['g u', t('Go to users (administrators)')],
                  ['g s', t('Go to settings')],
              ] as [string, string][])
            : []),
    ];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent data-slot="shortcuts-help">
                <DialogTitle>{t('Keyboard shortcuts')}</DialogTitle>
                <DialogDescription>
                    {shortcuts
                        ? t(
                              'Single-key shortcuts can be turned off in Appearance settings.',
                          )
                        : t(
                              'Single-key shortcuts are off; turn them on in Appearance settings.',
                          )}
                </DialogDescription>
                <table className="w-full text-sm">
                    <caption className="sr-only">
                        {t('Keyboard shortcuts')}
                    </caption>
                    <thead className="sr-only">
                        <tr>
                            <th scope="col">{t('Keys')}</th>
                            <th scope="col">{t('Action')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map(([keys, action]) => (
                            <tr key={keys} className="border-b last:border-b-0">
                                <td
                                    className="py-2 pe-4 whitespace-nowrap"
                                    dir="ltr"
                                >
                                    {keys.split(' ').map((key, index) => (
                                        <kbd
                                            key={`${key}-${index}`}
                                            className="me-1 rounded border bg-muted px-1.5 py-0.5 font-mono text-xs"
                                        >
                                            {key}
                                        </kbd>
                                    ))}
                                </td>
                                <td className="py-2">{action}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </DialogContent>
        </Dialog>
    );
}
