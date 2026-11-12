import { Head, router, usePage } from '@inertiajs/react';
import { ShieldMinus, ShieldPlus } from 'lucide-react';
import { lazy, Suspense, useCallback, useState } from 'react';
import UserRoleController from '@/actions/App/Http/Controllers/Admin/UserRoleController';
import SavedViewController from '@/actions/App/Http/Controllers/Tables/SavedViewController';
import TableColumnsController from '@/actions/App/Http/Controllers/Tables/TableColumnsController';
import { roleLabel } from '@/components/activity-timeline';
import type { SelectedUser } from '@/components/admin/user-details';
import {
    BulkActionBar,
    BulkResultPanel,
} from '@/components/data-table/bulk-actions';
import ColumnSettings from '@/components/data-table/column-settings';
import DataTable from '@/components/data-table/data-table';
import type { ColumnDef } from '@/components/data-table/data-table';
import SavedViews from '@/components/data-table/saved-views';
import TablePagination from '@/components/data-table/table-pagination';
import TableToolbar from '@/components/data-table/table-toolbar';
import Drawer from '@/components/drawer';
import Heading from '@/components/heading';
import { SkeletonText } from '@/components/skeletons';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTableNavigation } from '@/hooks/use-table-navigation';
import { useTranslations } from '@/hooks/use-translations';
import { index as usersIndex } from '@/routes/admin/users';
import type {
    BulkResult,
    ColumnPrefs,
    PageMeta,
    SavedViewItem,
    TableState,
} from '@/types/table';

type UserRow = {
    id: number;
    name: string;
    email: string;
    roles: string[];
    two_factor: boolean;
    created_at: string | null;
};

type Props = {
    users: { rows: UserRow[] } & PageMeta;
    state: TableState;
    columns: ColumnPrefs;
    filterOptions: Record<string, string[]>;
    roles: string[];
    views: SavedViewItem[];
    selected: SelectedUser | null;
};

const TABLE_PROPS = ['users', 'state'];

// Used only after a click: loaded then, to keep the list page small.
const UserDetails = lazy(() => import('@/components/admin/user-details'));
const BulkRoleDialog = lazy(
    () => import('@/components/admin/bulk-role-dialog'),
);

export default function UsersIndex({
    users,
    state,
    columns,
    roles,
    views,
    selected,
}: Props) {
    const { t, locale } = useTranslations();
    const { url } = usePage();
    const { visit, busy } = useTableNavigation(state, TABLE_PROPS);
    const [selection, setSelection] = useState<number[]>([]);
    const [result, setResult] = useState<BulkResult | null>(null);
    const [bulk, setBulk] = useState<'assign' | 'revoke' | null>(null);
    const [openingId, setOpeningId] = useState<number | null>(null);
    const dates = new Intl.DateTimeFormat(locale === 'ar' ? 'ar' : 'en', {
        dateStyle: 'medium',
    });

    const columnDefs: ColumnDef<UserRow>[] = [
        {
            key: 'name',
            header: t('Name'),
            sortable: true,
            cell: (user) => user.name,
        },
        {
            key: 'email',
            header: t('Email address'),
            sortable: true,
            cell: (user) => <span dir="ltr">{user.email}</span>,
        },
        {
            key: 'roles',
            header: t('Roles'),
            cell: (user) =>
                user.roles.length === 0 ? (
                    <span className="text-muted-foreground">{t('None')}</span>
                ) : (
                    <span className="flex flex-wrap gap-1">
                        {user.roles.map((role) => (
                            <Badge key={role} variant="secondary">
                                {roleLabel(t, role)}
                            </Badge>
                        ))}
                    </span>
                ),
        },
        {
            key: 'two_factor',
            header: t('Two-factor'),
            cell: (user) => (user.two_factor ? t('Enabled') : t('Not enabled')),
        },
        {
            key: 'created_at',
            header: t('Joined'),
            sortable: true,
            cell: (user) =>
                user.created_at ? (
                    <time dateTime={user.created_at}>
                        {dates.format(new Date(user.created_at))}
                    </time>
                ) : null,
        },
    ];
    const hideable = new Set(['email', 'roles', 'two_factor', 'created_at']);

    const onSearch = useCallback(
        (search: string) => visit({ search }),
        [visit],
    );

    const openUser = (id: number | null) => {
        setOpeningId(id);
        const query = Object.fromEntries(
            new URL(url, window.location.origin).searchParams,
        );
        delete query.user;

        router.get(
            window.location.pathname,
            id === null ? query : { ...query, user: String(id) },
            {
                only: ['selected'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => setOpeningId(null),
            },
        );
    };

    const runBulk = (role: string) => {
        if (!bulk) {
            return;
        }

        router.post(
            UserRoleController.url(),
            { action: bulk, role, ids: selection },
            {
                only: ['users', 'selected'],
                preserveState: true,
                preserveScroll: true,
                onFlash: (flash) => setResult(flash.bulkResult ?? null),
                onSuccess: () => {
                    setBulk(null);
                    setSelection([]);
                },
            },
        );
    };

    const filtered =
        state.search !== '' || Object.keys(state.filters).length > 0;

    return (
        <>
            <Head title={t('Users')} />
            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title={t('Users')}
                    description={t('People who can sign in, and their roles.')}
                />

                <TableToolbar
                    search={state.search}
                    values={state.filters}
                    filters={[
                        {
                            key: 'role',
                            label: t('Role'),
                            options: [
                                ...roles.map((role) => ({
                                    value: role,
                                    label: roleLabel(t, role),
                                })),
                                { value: 'none', label: t('No role') },
                            ],
                        },
                        {
                            key: 'two_factor',
                            label: t('Two-factor'),
                            options: [
                                { value: 'enabled', label: t('Enabled') },
                                { value: 'disabled', label: t('Not enabled') },
                            ],
                        },
                    ]}
                    onSearch={onSearch}
                    onFilter={(key, value) => {
                        const filters = { ...state.filters };

                        if (value === null) {
                            delete filters[key];
                        } else {
                            filters[key] = value;
                        }

                        visit({ filters });
                    }}
                    onClear={() => visit({ search: '', filters: {} })}
                >
                    <ColumnSettings
                        columns={columnDefs.map((column) => ({
                            key: column.key,
                            header: column.header,
                            hideable: hideable.has(column.key),
                        }))}
                        prefs={columns}
                        onSave={(prefs) =>
                            router.put(
                                TableColumnsController.url('users'),
                                prefs,
                                {
                                    only: ['columns'],
                                    preserveState: true,
                                    preserveScroll: true,
                                },
                            )
                        }
                    />
                    <SavedViews
                        views={views}
                        state={state}
                        columns={columns}
                        saveUrl={SavedViewController.store.url('users')}
                        deleteForm={(view) =>
                            SavedViewController.destroy.form({
                                table: 'users',
                                view: view.id,
                            })
                        }
                        onApply={(view) => {
                            if (view.state.columns) {
                                router.put(
                                    TableColumnsController.url('users'),
                                    view.state.columns,
                                    {
                                        only: ['columns'],
                                        preserveState: true,
                                        preserveScroll: true,
                                        onSuccess: () =>
                                            visit({ ...view.state }),
                                    },
                                );
                            } else {
                                visit({ ...view.state });
                            }
                        }}
                    />
                </TableToolbar>

                <BulkActionBar
                    count={selection.length}
                    onClear={() => setSelection([])}
                >
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setBulk('assign')}
                        data-test="bulk-assign"
                    >
                        <ShieldPlus aria-hidden />
                        {t('Grant a role')}
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setBulk('revoke')}
                        data-test="bulk-revoke"
                    >
                        <ShieldMinus aria-hidden />
                        {t('Remove a role')}
                    </Button>
                </BulkActionBar>

                {result && (
                    <BulkResultPanel
                        result={result}
                        onDismiss={() => setResult(null)}
                    />
                )}

                <DataTable
                    caption={t('Users')}
                    columns={columnDefs}
                    prefs={columns}
                    rows={users.rows}
                    rowId={(user) => user.id}
                    rowLabel={(user) => user.name}
                    state={state}
                    busy={busy}
                    filtered={filtered}
                    onClearFilters={() => visit({ search: '', filters: {} })}
                    onSort={(column) =>
                        visit({
                            sort: column,
                            direction:
                                state.sort === column &&
                                state.direction === 'asc'
                                    ? 'desc'
                                    : 'asc',
                        })
                    }
                    selected={selection}
                    onSelectedChange={setSelection}
                    rowAction={(user) => (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => openUser(user.id)}
                            aria-label={t('Details of :name', {
                                name: user.name,
                            })}
                            data-test="open-user"
                        >
                            {t('Details')}
                        </Button>
                    )}
                />

                <TablePagination
                    meta={users}
                    perPage={state.per_page}
                    onPage={(page) => visit({ page })}
                    onPerPage={(perPage) => visit({ per_page: perPage })}
                />
            </div>

            <Drawer
                open={selected !== null || openingId !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        openUser(null);
                    }
                }}
                title={selected?.name ?? t('Loading')}
                description={selected?.email}
            >
                {selected === null ? (
                    <SkeletonText lines={6} />
                ) : (
                    <Suspense fallback={<SkeletonText lines={6} />}>
                        <UserDetails user={selected} />
                    </Suspense>
                )}
            </Drawer>

            {bulk !== null && (
                <Suspense fallback={null}>
                    <BulkRoleDialog
                        action={bulk}
                        roles={roles}
                        count={selection.length}
                        onConfirm={runBulk}
                        onClose={() => setBulk(null)}
                    />
                </Suspense>
            )}
        </>
    );
}

UsersIndex.layout = {
    breadcrumbs: [{ title: 'Users', href: usersIndex() }],
};
