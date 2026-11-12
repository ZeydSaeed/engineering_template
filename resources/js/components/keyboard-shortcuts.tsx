import { router } from '@inertiajs/react';
import { lazy, Suspense, useEffect, useRef, useState } from 'react';
import { usePreferences } from '@/hooks/use-preferences';
import { useMainNavigation } from '@/hooks/use-main-navigation';
import { toUrl } from '@/lib/utils';
import { dashboard } from '@/routes';
import { edit as editProfile } from '@/routes/profile';

// Loaded when first opened; only the key listener is in every page.
const CommandPalette = lazy(() => import('@/components/command-palette'));
const ShortcutsHelp = lazy(() => import('@/components/shortcuts-help'));

/** Opens the search from anywhere (a button, a page). */
export const OPEN_SEARCH_EVENT = 'platform:open-search';

/**
 * Keyboard shortcuts for the signed-in application (improvement #17):
 *  - Ctrl+K / ⌘K: search (always on);
 *  - ?: this list; /: the page's search field; g then d / u / s: go to
 *    the dashboard, users, settings — single keys, which the person can
 *    turn off in Appearance (WCAG 2.1.4, ADR-0028).
 * Ignored while typing in a field.
 */
export default function KeyboardShortcuts() {
    const { shortcuts } = usePreferences();
    const navigation = useMainNavigation();
    const [searchOpen, setSearchOpen] = useState(false);
    const [searchUsed, setSearchUsed] = useState(false);
    const [helpOpen, setHelpOpen] = useState(false);
    const [helpUsed, setHelpUsed] = useState(false);
    const pendingG = useRef<number | null>(null);

    const openSearch = () => {
        setSearchUsed(true);
        setSearchOpen(true);
    };

    useEffect(() => {
        const usersUrl = navigation.find((item) => item.title === 'Users');

        const typing = (target: EventTarget | null) =>
            target instanceof HTMLElement &&
            (target.isContentEditable ||
                ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));

        const onKeyDown = (event: KeyboardEvent) => {
            if (
                (event.ctrlKey || event.metaKey) &&
                event.key.toLowerCase() === 'k'
            ) {
                event.preventDefault();
                openSearch();

                return;
            }

            if (
                !shortcuts ||
                event.ctrlKey ||
                event.metaKey ||
                event.altKey ||
                typing(event.target)
            ) {
                return;
            }

            if (pendingG.current !== null) {
                window.clearTimeout(pendingG.current);
                pendingG.current = null;
                const target = {
                    d: dashboard(),
                    u: usersUrl?.href,
                    s: editProfile(),
                }[event.key];

                if (target) {
                    event.preventDefault();
                    router.visit(toUrl(target));
                }

                return;
            }

            if (event.key === '?') {
                event.preventDefault();
                setHelpUsed(true);
                setHelpOpen(true);
            } else if (event.key === '/') {
                const field = document.querySelector<HTMLElement>(
                    '[data-shortcut-search]',
                );

                if (field) {
                    event.preventDefault();
                    field.focus();
                }
            } else if (event.key === 'g') {
                pendingG.current = window.setTimeout(() => {
                    pendingG.current = null;
                }, 1000);
            }
        };

        const onOpenSearch = () => openSearch();

        document.addEventListener('keydown', onKeyDown);
        window.addEventListener(OPEN_SEARCH_EVENT, onOpenSearch);

        return () => {
            document.removeEventListener('keydown', onKeyDown);
            window.removeEventListener(OPEN_SEARCH_EVENT, onOpenSearch);
        };
    }, [shortcuts, navigation]);

    return (
        <>
            {searchUsed && (
                <Suspense fallback={null}>
                    <CommandPalette
                        open={searchOpen}
                        onOpenChange={setSearchOpen}
                    />
                </Suspense>
            )}

            {helpUsed && (
                <Suspense fallback={null}>
                    <ShortcutsHelp
                        open={helpOpen}
                        onOpenChange={setHelpOpen}
                        shortcuts={shortcuts}
                    />
                </Suspense>
            )}
        </>
    );
}
