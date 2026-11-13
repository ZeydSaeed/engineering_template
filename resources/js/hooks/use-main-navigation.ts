import { usePage } from '@inertiajs/react';
import {
    BarChart3,
    Bell,
    ClipboardList,
    LayoutGrid,
    Users,
} from 'lucide-react';
import RequestController from '@/actions/App/Modules/Requests/Http/RequestController';
import { dashboard } from '@/routes';
import { reports } from '@/routes/admin';
import { index as usersIndex } from '@/routes/admin/users';
import { index as notificationsIndex } from '@/routes/notifications';
import type { NavItem } from '@/types';

/**
 * The main navigation, for the sidebar and the header layouts alike. An
 * item needing a permission shows only to people who have it; the server
 * still refuses the page to anyone else. Titles are English keys,
 * translated where they are shown.
 */
export function useMainNavigation(): NavItem[] {
    const { permissions } = usePage().props.auth;

    const items: (NavItem & { permission?: string })[] = [
        { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
        { title: 'Notifications', href: notificationsIndex(), icon: Bell },
        // Everyone may ask for something (Requests module, ADR-0033).
        {
            title: 'Requests',
            href: RequestController.index(),
            icon: ClipboardList,
        },
        {
            title: 'Users',
            href: usersIndex(),
            icon: Users,
            permission: 'roles.manage',
        },
        {
            title: 'Reports',
            href: reports(),
            icon: BarChart3,
            permission: 'audit.view',
        },
    ];

    return items
        .filter(
            (item) => !item.permission || permissions.includes(item.permission),
        )
        .map(({ permission: _permission, ...item }) => item);
}
