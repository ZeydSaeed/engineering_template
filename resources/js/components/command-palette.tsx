import { router } from '@inertiajs/react';
import { FileText, Search, User } from 'lucide-react';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useMainNavigation } from '@/hooks/use-main-navigation';
import { useTranslations } from '@/hooks/use-translations';
import { currentOpener } from '@/lib/opener';
import { cn, toUrl } from '@/lib/utils';
import { index as documentsIndex } from '@/routes/documents';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { search } from '@/routes';
import { edit as editSecurity } from '@/routes/security';

type Result = {
    id: string;
    group: 'pages' | 'users';
    label: string;
    detail?: string;
    url: string;
};

/**
 * One search box for pages and records (improvement #5), opened with
 * Ctrl+K / ⌘K or the search button. ARIA combobox with a listbox: arrows
 * move, Enter opens, Esc closes; the count is announced. Pages come from
 * the navigation the person already has; records from the server, limited
 * to what they may see (ADR-0028).
 */
export default function CommandPalette({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { t } = useTranslations();
    const navigation = useMainNavigation();
    const listId = useId();
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const [users, setUsers] = useState<Result[]>([]);
    // Opened by a shortcut or an event, Radix has no trigger to return
    // focus to: keep what had focus (as Drawer does).
    const opener = useRef<HTMLElement | null>(null);

    useEffect(() => {
        if (open) {
            opener.current = currentOpener();
        }
    }, [open]);

    const pages = useMemo<Result[]>(
        () =>
            [
                ...navigation.map((item) => ({
                    title: item.title,
                    href: toUrl(item.href),
                })),
                { title: 'Profile', href: toUrl(editProfile()) },
                { title: 'Security', href: toUrl(editSecurity()) },
                { title: 'Appearance', href: toUrl(editAppearance()) },
                { title: 'Documents', href: toUrl(documentsIndex()) },
            ].map((page) => ({
                id: `page-${page.href}`,
                group: 'pages' as const,
                label: t(page.title),
                url: page.href,
            })),
        [navigation, t],
    );

    // Records from the server, after a short pause in typing.
    useEffect(() => {
        const term = query.trim();

        if (!open || term.length < 2) {
            setUsers([]);

            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(`${search.url()}?q=${encodeURIComponent(term)}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then((response) =>
                    response.ok ? response.json() : { users: [] },
                )
                .then(
                    (data: {
                        users: {
                            id: number;
                            name: string;
                            email: string;
                            url: string;
                        }[];
                    }) =>
                        setUsers(
                            data.users.map((user) => ({
                                id: `user-${user.id}`,
                                group: 'users',
                                label: user.name,
                                detail: user.email,
                                url: user.url,
                            })),
                        ),
                )
                .catch(() => undefined);
        }, 250);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [query, open]);

    const term = query.trim().toLocaleLowerCase();
    const results = [
        ...pages.filter(
            (page) =>
                term === '' || page.label.toLocaleLowerCase().includes(term),
        ),
        ...users,
    ];
    const current = results[Math.min(active, results.length - 1)];

    const go = (result: Result | undefined) => {
        if (!result) {
            return;
        }

        onOpenChange(false);
        router.visit(result.url);
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                onOpenChange(next);

                if (!next) {
                    setQuery('');
                    setActive(0);
                }
            }}
        >
            <DialogContent
                className="gap-0 p-0 sm:max-w-lg"
                data-slot="command-palette"
                onCloseAutoFocus={(event) => {
                    if (opener.current?.isConnected) {
                        event.preventDefault();
                        opener.current.focus();
                    }
                }}
            >
                <DialogTitle className="sr-only">{t('Search')}</DialogTitle>
                <DialogDescription className="sr-only">
                    {t(
                        'Type to find pages and records; use the arrow keys and Enter.',
                    )}
                </DialogDescription>
                <div className="flex items-center gap-2 border-b px-3">
                    <Search
                        className="size-4 text-muted-foreground"
                        aria-hidden
                    />
                    <input
                        role="combobox"
                        aria-expanded={results.length > 0}
                        aria-controls={listId}
                        aria-activedescendant={
                            current ? `${listId}-${current.id}` : undefined
                        }
                        aria-autocomplete="list"
                        aria-label={t('Search')}
                        value={query}
                        autoFocus
                        onChange={(event) => {
                            setQuery(event.target.value);
                            setActive(0);
                        }}
                        onKeyDown={(event) => {
                            if (event.key === 'ArrowDown') {
                                event.preventDefault();
                                setActive((index) =>
                                    Math.min(index + 1, results.length - 1),
                                );
                            } else if (event.key === 'ArrowUp') {
                                event.preventDefault();
                                setActive((index) => Math.max(index - 1, 0));
                            } else if (event.key === 'Enter') {
                                event.preventDefault();
                                go(current);
                            }
                        }}
                        className="h-12 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                        placeholder={t('Search pages and people')}
                        data-test="command-input"
                    />
                </div>

                <p aria-live="polite" className="sr-only">
                    {t(':count results', { count: results.length })}
                </p>

                <ul
                    id={listId}
                    role="listbox"
                    aria-label={t('Results')}
                    className="max-h-80 overflow-y-auto p-2"
                >
                    {results.length === 0 && (
                        <li
                            role="presentation"
                            className="px-3 py-6 text-center text-sm text-muted-foreground"
                        >
                            {t('No results')}
                        </li>
                    )}
                    {results.map((result) => {
                        const selected = result === current;
                        const Icon = result.group === 'users' ? User : FileText;

                        return (
                            <li
                                key={result.id}
                                id={`${listId}-${result.id}`}
                                role="option"
                                aria-selected={selected}
                                data-result={result.group}
                                onMouseEnter={() =>
                                    setActive(results.indexOf(result))
                                }
                                onClick={() => go(result)}
                                className={cn(
                                    'flex cursor-pointer items-center gap-3 rounded-md px-3 py-2 text-sm',
                                    selected &&
                                        'bg-accent text-accent-foreground',
                                )}
                            >
                                <Icon
                                    className="size-4 text-muted-foreground"
                                    aria-hidden
                                />
                                <span className="flex-1">{result.label}</span>
                                {result.detail && (
                                    <span
                                        dir="ltr"
                                        className="text-xs text-muted-foreground"
                                    >
                                        {result.detail}
                                    </span>
                                )}
                                <span className="sr-only">
                                    {result.group === 'users'
                                        ? t('Person')
                                        : t('Page')}
                                </span>
                            </li>
                        );
                    })}
                </ul>
            </DialogContent>
        </Dialog>
    );
}
