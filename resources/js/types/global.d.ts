import type { Auth } from '@/types/auth';
import type { BulkResult } from '@/types/table';
import type { FlashToast } from '@/types/ui';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            locale: string;
            locales: string[];
            direction: 'ltr' | 'rtl';
            translations: Record<string, string>;
            [key: string]: unknown;
        };
        // One-off data a redirect carries (Inertia::flash on the server).
        flashDataType: {
            toast?: FlashToast;
            bulkResult?: BulkResult;
        };
    }
}
