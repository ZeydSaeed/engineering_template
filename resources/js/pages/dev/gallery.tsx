import { Head } from '@inertiajs/react';
import { Inbox, RotateCcw } from 'lucide-react';
import type { ReactNode } from 'react';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
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
            </div>
        </>
    );
}

Gallery.layout = {
    breadcrumbs: [{ title: 'Component gallery', href: '/ui' }],
};
