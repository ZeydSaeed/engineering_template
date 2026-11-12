import { Head } from '@inertiajs/react';
import { Inbox, RotateCcw } from 'lucide-react';
import type { ReactNode } from 'react';
import ActivityTimeline from '@/components/activity-timeline';
import ConfirmAction from '@/components/confirm-action';
import {
    BulkActionBar,
    BulkResultPanel,
} from '@/components/data-table/bulk-actions';
import TablePagination from '@/components/data-table/table-pagination';
import Drawer from '@/components/drawer';
import EmptyState from '@/components/empty-state';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import {
    SkeletonCard,
    SkeletonTable,
    SkeletonText,
} from '@/components/skeletons';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslations } from '@/hooks/use-translations';

/**
 * Every shared component in each of its states (ADR-0025). Development and
 * browser tests only: the route exists in platform.ui.gallery_environments.
 * The browser tests check this page with axe in both languages, both themes
 * and at phone, tablet and desktop widths.
 */
function Section({
    id,
    title,
    description,
    children,
}: {
    id: string;
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <section
            aria-labelledby={`${id}-title`}
            data-gallery={id}
            className="space-y-4"
        >
            <div id={`${id}-title`}>
                <Heading
                    variant="small"
                    title={title}
                    description={description}
                />
            </div>
            {children}
        </section>
    );
}

export default function Gallery() {
    const { t } = useTranslations();

    return (
        <>
            <Head title={t('Component gallery')} />
            <div className="flex flex-col gap-10 p-4">
                <h1 className="text-xl font-semibold">
                    {t('Component gallery')}
                </h1>

                <Section
                    id="empty-state"
                    title={t('Empty states')}
                    description={t(
                        'What a list shows when it has nothing to show.',
                    )}
                >
                    <div className="grid gap-4 lg:grid-cols-3">
                        <EmptyState
                            icon={Inbox}
                            title={t('Nothing here yet')}
                            description={t(
                                'The modules you add will show their work on this page.',
                            )}
                            headingLevel={3}
                        />
                        <EmptyState
                            kind="no-results"
                            title={t('No results')}
                            description={t('Nothing matches these filters.')}
                            action={
                                <Button variant="outline" size="sm">
                                    {t('Clear filters')}
                                </Button>
                            }
                            headingLevel={3}
                        />
                        <EmptyState
                            kind="error"
                            title={t('Could not load')}
                            description={t(
                                'Check your connection and try again.',
                            )}
                            action={
                                <Button variant="outline" size="sm">
                                    <RotateCcw className="rtl:-scale-x-100" />
                                    {t('Try again')}
                                </Button>
                            }
                            headingLevel={3}
                        />
                    </div>
                </Section>

                <Section
                    id="form-field"
                    title={t('Form fields')}
                    description={t(
                        'Label, help text, error and required marker, linked for screen readers.',
                    )}
                >
                    <div className="grid max-w-md gap-6">
                        <FormField
                            name="gallery_name"
                            label={t('Full name')}
                            description={t(
                                'As it appears on official documents.',
                            )}
                            required
                        >
                            {(control) => <Input {...control} />}
                        </FormField>
                        <FormField
                            name="gallery_email"
                            label={t('Email address')}
                            error={t('Enter a valid email address.')}
                            required
                        >
                            {(control) => (
                                <Input
                                    {...control}
                                    type="email"
                                    dir="ltr"
                                    defaultValue="not-an-email"
                                />
                            )}
                        </FormField>
                    </div>
                </Section>

                <Section
                    id="skeletons"
                    title={t('Loading placeholders')}
                    description={t(
                        'Shaped like the content they stand for; announced once as loading.',
                    )}
                >
                    <div className="grid gap-4 lg:grid-cols-3">
                        <SkeletonText />
                        <SkeletonCard />
                        <SkeletonTable rows={3} columns={3} />
                    </div>
                </Section>

                <Section
                    id="dialogs"
                    title={t('Dialogs and drawers')}
                    description={t(
                        'Focus stays inside while open, Esc closes, focus returns to the button that opened it.',
                    )}
                >
                    <div className="flex flex-wrap gap-3">
                        <Drawer
                            title={t('Record details')}
                            description={t('Opens from the reading end.')}
                            trigger={
                                <Button
                                    variant="outline"
                                    data-test="open-drawer"
                                >
                                    {t('Open drawer')}
                                </Button>
                            }
                            footer={
                                <Button variant="secondary">
                                    {t('Close')}
                                </Button>
                            }
                        >
                            <FormField name="drawer_note" label={t('Note')}>
                                {(control) => <Input {...control} />}
                            </FormField>
                        </Drawer>

                        <ConfirmAction
                            trigger={
                                <Button
                                    variant="destructive"
                                    data-test="open-confirm"
                                >
                                    {t('Remove')}
                                </Button>
                            }
                            title={t('Remove this item?')}
                            description={t('This cannot be undone.')}
                            confirmLabel={t('Remove')}
                            form={{ action: '/ui', method: 'get' }}
                        />
                    </div>
                </Section>

                <Section
                    id="data"
                    title={t('Data')}
                    description={t(
                        'Pieces of the server-side table; the users page shows them working together.',
                    )}
                >
                    <div className="space-y-4">
                        <BulkActionBar count={3} onClear={() => undefined}>
                            <Button size="sm" variant="outline">
                                {t('Grant a role')}
                            </Button>
                        </BulkActionBar>
                        <BulkResultPanel
                            result={{
                                succeeded: [1, 2],
                                failed: [
                                    {
                                        id: 3,
                                        name: 'Browser Test',
                                        reason: t(
                                            'You cannot change your own roles.',
                                        ),
                                    },
                                ],
                            }}
                            onDismiss={() => undefined}
                        />
                        <TablePagination
                            meta={{
                                page: 2,
                                last_page: 5,
                                total: 120,
                                from: 26,
                                to: 50,
                            }}
                            perPage={25}
                            onPage={() => undefined}
                            onPerPage={() => undefined}
                        />
                    </div>
                </Section>

                <Section
                    id="timeline"
                    title={t('Activity')}
                    description={t("A record's history from the audit trail.")}
                >
                    <div className="max-w-md">
                        <ActivityTimeline
                            entries={[
                                {
                                    id: 2,
                                    action: 'access.role_assigned',
                                    occurred_at: '2026-11-12T10:30:00Z',
                                    actor: 'Browser Test',
                                    subject_is_record: true,
                                    details: { role: 'auditor' },
                                },
                                {
                                    id: 1,
                                    action: 'auth.login',
                                    occurred_at: '2026-11-12T09:00:00Z',
                                    actor: 'Person 01',
                                    subject_is_record: false,
                                    details: {},
                                },
                            ]}
                        />
                    </div>
                </Section>
            </div>
        </>
    );
}

Gallery.layout = {
    breadcrumbs: [{ title: 'Component gallery', href: '/ui' }],
};
