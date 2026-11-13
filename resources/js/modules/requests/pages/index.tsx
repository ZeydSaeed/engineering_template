import { Head, Link, router } from '@inertiajs/react';
import { FilePlus } from 'lucide-react';
import { useCallback } from 'react';
import SavedViewController from '@/actions/App/Http/Controllers/Tables/SavedViewController';
import TableColumnsController from '@/actions/App/Http/Controllers/Tables/TableColumnsController';
import RequestController from '@/actions/App/Modules/Requests/Http/RequestController';
import ColumnSettings from '@/components/data-table/column-settings';
import DataTable from '@/components/data-table/data-table';
import type { ColumnDef } from '@/components/data-table/data-table';
import SavedViews from '@/components/data-table/saved-views';
import TablePagination from '@/components/data-table/table-pagination';
import TableToolbar from '@/components/data-table/table-toolbar';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { useTableNavigation } from '@/hooks/use-table-navigation';
import { useTranslations } from '@/hooks/use-translations';
import type {
    ColumnPrefs,
    PageMeta,
    SavedViewItem,
    TableState,
} from '@/types/table';
import StatusBadge, { statusLabel } from '../components/status-badge';
import { formatters } from '../format';
import type { RequestRow } from '../types';

type Props = {
    requests: { rows: RequestRow[] } & PageMeta;
    state: TableState;
    columns: ColumnPrefs;
    filterOptions: Record<string, string[]>;
    views: SavedViewItem[];
    canDecide: boolean;
    seesAll: boolean;
};

const TABLE = 'requests';
const TABLE_PROPS = ['requests', 'state'];

/**
 * Requests (ADR-0033): one's own, or everyone's for those who may see
 * them; a server-side table with columns, filters and saved views
 * (ADR-0026). Approvers filter on what waits for them.
 */
export default function RequestsIndex({
    requests,
    state,
    columns,
    filterOptions,
    views,
    canDecide,
    seesAll,
}: Props) {
    const { t, locale } = useTranslations();
    const format = formatters(locale);
    const { visit, busy } = useTableNavigation(state, TABLE_PROPS);
    const filtered =
        state.search !== '' || Object.keys(state.filters).length > 0;
    const onSearch = useCallback(
        (search: string) => visit({ search }),
        [visit],
    );

    const columnDefs: ColumnDef<RequestRow>[] = [
        {
            key: 'title',
            header: t('Title'),
            sortable: true,
            cell: (row) => (
                <Link
                    href={RequestController.show.url(row.uuid)}
                    className="font-medium text-primary underline-offset-4 hover:underline focus-visible:rounded-sm focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    dir="auto"
                    data-test="open-request"
                >
                    {row.title}
                </Link>
            ),
        },
        {
            key: 'requester',
            header: t('Requested by'),
            cell: (row) => <span dir="auto">{row.requester}</span>,
        },
        {
            key: 'status',
            header: t('Status'),
            sortable: true,
            cell: (row) => <StatusBadge status={row.status} />,
        },
        {
            key: 'amount',
            header: t('Amount'),
            sortable: true,
            className: 'text-end tabular-nums',
            cell: (row) =>
                format.amount(row.amount) ?? (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'submitted_at',
            header: t('Submitted'),
            sortable: true,
            cell: (row) =>
                row.submitted_at ? (
                    <time dateTime={row.submitted_at}>
                        {format.date(row.submitted_at)}
                    </time>
                ) : null,
        },
        {
            key: 'created_at',
            header: t('Created'),
            sortable: true,
            cell: (row) => (
                <time dateTime={row.created_at}>
                    {format.date(row.created_at)}
                </time>
            ),
        },
    ];
    const hideable = new Set([
        'requester',
        'amount',
        'submitted_at',
        'created_at',
    ]);

    const saveColumns = (prefs: ColumnPrefs, onSuccess?: () => void) =>
        router.put(TableColumnsController.url(TABLE), prefs, {
            only: ['columns'],
            preserveState: true,
            preserveScroll: true,
            onSuccess,
        });

    return (
        <>
            <Head title={t('Requests')} />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={t('Requests')}
                        description={
                            seesAll
                                ? t(
                                      'Everyone’s requests, and the ones waiting for a decision.',
                                  )
                                : t(
                                      'What you have asked for, and where it stands.',
                                  )
                        }
                    />
                    <Button asChild>
                        <Link
                            href={RequestController.create.url()}
                            data-test="new-request"
                        >
                            <FilePlus aria-hidden />
                            {t('New request')}
                        </Link>
                    </Button>
                </div>

                <TableToolbar
                    search={state.search}
                    values={state.filters}
                    filters={[
                        {
                            key: 'status',
                            label: t('Status'),
                            options: (filterOptions.status ?? []).map(
                                (status) => ({
                                    value: status,
                                    label: statusLabel(t, status),
                                }),
                            ),
                        },
                        ...(seesAll || canDecide
                            ? [
                                  {
                                      key: 'scope',
                                      label: t('Show'),
                                      options: [
                                          {
                                              value: 'mine',
                                              label: t('My requests'),
                                          },
                                          ...(canDecide
                                              ? [
                                                    {
                                                        value: 'to_decide',
                                                        label: t(
                                                            'Waiting for my decision',
                                                        ),
                                                    },
                                                ]
                                              : []),
                                      ],
                                  },
                              ]
                            : []),
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
                        onSave={(prefs) => saveColumns(prefs)}
                    />
                    <SavedViews
                        views={views}
                        state={state}
                        columns={columns}
                        saveUrl={SavedViewController.store.url(TABLE)}
                        deleteForm={(view) =>
                            SavedViewController.destroy.form({
                                table: TABLE,
                                view: view.id,
                            })
                        }
                        onApply={(view) => {
                            if (view.state.columns) {
                                saveColumns(view.state.columns, () =>
                                    visit({ ...view.state }),
                                );
                            } else {
                                visit({ ...view.state });
                            }
                        }}
                    />
                </TableToolbar>

                <DataTable
                    caption={t('Requests')}
                    columns={columnDefs}
                    prefs={columns}
                    rows={requests.rows}
                    rowId={(row) => requests.rows.indexOf(row)}
                    rowLabel={(row) => row.title}
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
                />

                <TablePagination
                    meta={requests}
                    perPage={state.per_page}
                    onPage={(page) => visit({ page })}
                    onPerPage={(perPage) => visit({ per_page: perPage })}
                />
            </div>
        </>
    );
}

RequestsIndex.layout = {
    breadcrumbs: [{ title: 'Requests', href: RequestController.index.url() }],
};
