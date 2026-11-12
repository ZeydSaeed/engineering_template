import { usePage } from '@inertiajs/react';
import { LayoutGrid, Users } from 'lucide-react';
import { dashboard } from '@/routes';
import { index as usersIndex } from '@/routes/admin/users';
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
        {
            title: 'Users',
            href: usersIndex(),
            icon: Users,
            permission: 'roles.manage',
        },
    ];

    return items
        .filter(
            (item) => !item.permission || permissions.includes(item.permission),
        )
        .map(({ permission: _permission, ...item }) => item);
}
