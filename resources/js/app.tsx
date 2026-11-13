import { createInertiaApp } from '@inertiajs/react';
import { DirectionProvider } from '@radix-ui/react-direction';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
// Listens from the start: dialogs in lazy chunks need the click that
// opened them (lib/opener.ts).
import '@/lib/opener';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Pages of the application and of each module (ADR-0033): a module page is
// named by its place, modules/NAME/pages/PAGE. Each page is its own chunk.
const pages = import.meta.glob<{ default: unknown }>([
    './pages/**/*.tsx',
    './modules/*/pages/**/*.tsx',
]);

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: async (name) => {
        const path = name.startsWith('modules/') ? name : 'pages/' + name;
        const load = pages['./' + path + '.tsx'];

        if (!load) {
            throw new Error(`Page not found: ${name}`);
        }

        return (await load()).default as never;
    },
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        // The server sets <html dir>; a language change reloads the page.
        const direction =
            document.documentElement.dir === 'rtl' ? 'rtl' : 'ltr';

        return (
            <DirectionProvider dir={direction}>
                <TooltipProvider delayDuration={0}>
                    {app}
                    <Toaster
                        dir={direction}
                        position={
                            direction === 'rtl' ? 'bottom-left' : 'bottom-right'
                        }
                    />
                </TooltipProvider>
            </DirectionProvider>
        );
    },
    progress: {
        color: 'var(--primary)',
    },
});

// This will set light / dark mode on load...
initializeTheme();
