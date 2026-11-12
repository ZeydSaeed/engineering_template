import { usePage } from '@inertiajs/react';
import { useSyncExternalStore } from 'react';
import PreferencesController from '@/actions/App/Http/Controllers/Settings/PreferencesController';
import { csrfToken } from '@/lib/csrf';

export type WorkspacePreferences = {
    density: 'comfortable' | 'compact';
    shortcuts: boolean;
    // Signed in: choices are saved on the server.
    saved: boolean;
};

type Changes = Partial<{
    locale: string;
    appearance: 'light' | 'dark' | 'system';
    density: WorkspacePreferences['density'];
    shortcuts: boolean;
}>;

type Applied = Partial<Pick<WorkspacePreferences, 'density' | 'shortcuts'>>;

// Choices saved during this page's life, applied everywhere at once; the
// next full page load brings them from the server.
let applied: Applied = {};
const listeners = new Set<() => void>();

const notify = () => listeners.forEach((listener) => listener());

const subscribe = (listener: () => void) => {
    listeners.add(listener);

    return () => listeners.delete(listener);
};

/**
 * Saves workspace choices on the server for a signed-in person (#28,
 * ADR-0028), so they follow them to other devices. The change applies in
 * the whole interface at once. Each save is its own request (not an
 * Inertia visit, which would cancel the one before), so quick changes are
 * all kept; a refused save is undone.
 */
export function savePreferences(
    changes: Changes,
    onSuccess?: () => void,
): void {
    const before = applied;
    const { density, shortcuts } = changes;
    applied = {
        ...applied,
        ...(density !== undefined && { density }),
        ...(shortcuts !== undefined && { shortcuts }),
    };
    notify();

    fetch(PreferencesController.update.url(), {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(changes),
    })
        .then((response) => {
            if (!response.ok) {
                throw new Error(`Preferences not saved (${response.status}).`);
            }

            onSuccess?.();
        })
        .catch(() => {
            applied = before;
            notify();
        });
}

export function usePreferences(): WorkspacePreferences {
    const fromServer = usePage().props.preferences;
    const overlay = useSyncExternalStore(
        subscribe,
        () => applied,
        () => applied,
    );

    return { ...fromServer, ...overlay };
}
