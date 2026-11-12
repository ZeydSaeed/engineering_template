import { Head } from '@inertiajs/react';
import { Download, FileText } from 'lucide-react';
import DocumentController from '@/actions/App/Http/Controllers/Settings/DocumentController';
import EmptyState from '@/components/empty-state';
import FileUpload from '@/components/file-upload';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { useTranslations } from '@/hooks/use-translations';
import { index as documentsIndex } from '@/routes/documents';

type Document = {
    uuid: string;
    name: string;
    size: number;
    status: 'pending' | 'verified' | 'rejected';
    uploaded_at: string | null;
};

export default function Documents({
    documents,
    maxKb,
    extensions,
}: {
    documents: Document[];
    maxKb: number;
    extensions: string[];
}) {
    const { t, locale } = useTranslations();
    const dates = new Intl.DateTimeFormat(locale === 'ar' ? 'ar' : 'en', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
    const sizes = new Intl.NumberFormat(locale === 'ar' ? 'ar' : 'en', {
        maximumFractionDigits: 1,
    });

    const status = (document: Document) => {
        switch (document.status) {
            case 'verified':
                return <Badge variant="secondary">{t('Ready')}</Badge>;
            case 'rejected':
                return <Badge variant="destructive">{t('Rejected')}</Badge>;
            default:
                return <Badge variant="outline">{t('Being checked')}</Badge>;
        }
    };

    return (
        <>
            <Head title={t('Documents')} />
            <h1 className="sr-only">{t('Documents')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('Documents')}
                    description={t(
                        'Files you upload are checked before they can be downloaded.',
                    )}
                />

                <FileUpload
                    url={DocumentController.store.url()}
                    extensions={extensions}
                    maxKb={maxKb}
                    reload={['documents']}
                />

                {documents.length === 0 ? (
                    <EmptyState
                        icon={FileText}
                        title={t('No documents yet')}
                        description={t('Upload a file to keep it here.')}
                        headingLevel={3}
                    />
                ) : (
                    <ul
                        className="divide-y rounded-xl border"
                        data-slot="documents"
                    >
                        {documents.map((document) => (
                            <li
                                key={document.uuid}
                                className="flex flex-wrap items-center gap-3 px-4 py-3 text-sm"
                            >
                                <FileText
                                    className="size-4 text-muted-foreground"
                                    aria-hidden
                                />
                                <span
                                    className="min-w-0 flex-1 truncate font-medium"
                                    dir="auto"
                                >
                                    {document.name}
                                </span>
                                <span className="text-muted-foreground">
                                    {t(':size KB', {
                                        size: sizes.format(
                                            document.size / 1024,
                                        ),
                                    })}
                                </span>
                                {document.uploaded_at && (
                                    <time
                                        dateTime={document.uploaded_at}
                                        className="text-muted-foreground"
                                    >
                                        {dates.format(
                                            new Date(document.uploaded_at),
                                        )}
                                    </time>
                                )}
                                {status(document)}
                                {document.status === 'verified' && (
                                    <a
                                        href={DocumentController.show.url(
                                            document.uuid,
                                        )}
                                        className="inline-flex items-center gap-1 rounded-sm text-primary underline-offset-4 hover:underline focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                        aria-label={t('Download :name', {
                                            name: document.name,
                                        })}
                                    >
                                        <Download
                                            className="size-4"
                                            aria-hidden
                                        />
                                        {t('Download')}
                                    </a>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

Documents.layout = {
    breadcrumbs: [{ title: 'Documents', href: documentsIndex() }],
};
