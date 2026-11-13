import { Head, Link } from '@inertiajs/react';
import { Download, FileText, Pencil, Send } from 'lucide-react';
import AttachmentController from '@/actions/App/Modules/Requests/Http/AttachmentController';
import RequestController from '@/actions/App/Modules/Requests/Http/RequestController';
import ActivityTimeline from '@/components/activity-timeline';
import ConfirmAction from '@/components/confirm-action';
import EmptyState from '@/components/empty-state';
import FileUpload from '@/components/file-upload';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import DecisionPanel from '../components/decision-panel';
import StatusBadge from '../components/status-badge';
import { formatters } from '../format';
import type { Attachment, FullRequest } from '../types';

/**
 * One request (ADR-0033): what was asked, where it stands, its files and
 * its history; and what this person may do now (edit and submit their
 * draft, or decide if they may).
 */
export default function RequestShow({
    request,
    maxKb,
    extensions,
}: {
    request: FullRequest;
    maxKb: number;
    extensions: string[];
}) {
    const { t, locale } = useTranslations();
    const format = formatters(locale);
    const sizes = new Intl.NumberFormat(locale === 'ar' ? 'ar' : 'en', {
        maximumFractionDigits: 1,
    });

    const fileStatus = (file: Attachment) => {
        switch (file.status) {
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
            <Head title={request.title} />
            <div className="flex flex-col gap-6 p-4 lg:flex-row lg:items-start">
                <div className="flex min-w-0 flex-1 flex-col gap-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="flex min-w-0 flex-col gap-2">
                            <Heading title={request.title} />
                            <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                                <StatusBadge status={request.status} />
                                <span dir="auto">
                                    {t('Requested by :name', {
                                        name: request.requester ?? '',
                                    })}
                                </span>
                            </div>
                        </div>

                        {(request.can.update || request.can.submit) && (
                            <div className="flex flex-wrap gap-2">
                                {request.can.update && (
                                    <Button variant="outline" asChild>
                                        <Link
                                            href={RequestController.edit.url(
                                                request.uuid,
                                            )}
                                            data-test="edit-request"
                                        >
                                            <Pencil aria-hidden />
                                            {t('Change')}
                                        </Link>
                                    </Button>
                                )}
                                {request.can.submit && (
                                    <ConfirmAction
                                        trigger={
                                            <Button data-test="submit-request">
                                                <Send aria-hidden />
                                                {t('Submit')}
                                            </Button>
                                        }
                                        title={t('Submit this request?')}
                                        description={t(
                                            'Approvers will be told. After submitting, the request and its files cannot be changed.',
                                        )}
                                        confirmLabel={t('Submit')}
                                        tone="default"
                                        form={RequestController.submit.form(
                                            request.uuid,
                                        )}
                                    />
                                )}
                            </div>
                        )}
                    </div>

                    <dl
                        className="grid gap-4 rounded-xl border p-4 sm:grid-cols-2"
                        data-slot="request-details"
                    >
                        <div className="sm:col-span-2">
                            <dt className="text-sm text-muted-foreground">
                                {t('Details')}
                            </dt>
                            <dd className="mt-1 whitespace-pre-line" dir="auto">
                                {request.details}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                {t('Amount')}
                            </dt>
                            <dd className="mt-1 tabular-nums">
                                {format.amount(request.amount) ?? '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                {t('Submitted')}
                            </dt>
                            <dd className="mt-1">
                                {request.submitted_at ? (
                                    <time dateTime={request.submitted_at}>
                                        {format.dateTime(request.submitted_at)}
                                    </time>
                                ) : (
                                    t('Not yet')
                                )}
                            </dd>
                        </div>
                        {request.decided_at && (
                            <div className="sm:col-span-2" data-slot="decision">
                                <dt className="text-sm text-muted-foreground">
                                    {t('Decision')}
                                </dt>
                                <dd className="mt-1">
                                    {t(':status by :name', {
                                        status:
                                            request.status === 'approved'
                                                ? t('Approved')
                                                : t('Rejected'),
                                        name: request.decider ?? '',
                                    })}{' '}
                                    ·{' '}
                                    <time dateTime={request.decided_at}>
                                        {format.dateTime(request.decided_at)}
                                    </time>
                                    {request.decision_note && (
                                        <p
                                            className="mt-2 whitespace-pre-line text-muted-foreground"
                                            dir="auto"
                                        >
                                            {request.decision_note}
                                        </p>
                                    )}
                                </dd>
                            </div>
                        )}
                    </dl>

                    {request.can.decide && (
                        <DecisionPanel
                            uuid={request.uuid}
                            title={request.title}
                        />
                    )}

                    <section
                        aria-labelledby="files-heading"
                        className="flex flex-col gap-3"
                    >
                        <h2 id="files-heading" className="font-semibold">
                            {t('Files')}
                        </h2>
                        {request.can.update && (
                            <FileUpload
                                url={AttachmentController.store.url(
                                    request.uuid,
                                )}
                                extensions={extensions}
                                maxKb={maxKb}
                                reload={['request']}
                            />
                        )}
                        {request.attachments.length === 0 ? (
                            <EmptyState
                                icon={FileText}
                                title={t('No files')}
                                description={
                                    request.can.update
                                        ? t(
                                              'Add quotes, pictures or anything that helps the decision.',
                                          )
                                        : t('None were added to this request.')
                                }
                                headingLevel={3}
                            />
                        ) : (
                            <ul
                                className="divide-y rounded-xl border"
                                data-slot="attachments"
                            >
                                {request.attachments.map((file) => (
                                    <li
                                        key={file.uuid}
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
                                            {file.name}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {t(':size KB', {
                                                size: sizes.format(
                                                    file.size / 1024,
                                                ),
                                            })}
                                        </span>
                                        {fileStatus(file)}
                                        {file.status === 'verified' && (
                                            <a
                                                href={AttachmentController.show.url(
                                                    {
                                                        approvalRequest:
                                                            request.uuid,
                                                        file: file.uuid,
                                                    },
                                                )}
                                                className="inline-flex items-center gap-1 rounded-sm text-primary underline-offset-4 hover:underline focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                                aria-label={t(
                                                    'Download :name',
                                                    { name: file.name },
                                                )}
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
                    </section>
                </div>

                <aside
                    aria-labelledby="history-heading"
                    className="flex w-full flex-col gap-3 lg:w-80 lg:shrink-0"
                >
                    <h2 id="history-heading" className="font-semibold">
                        {t('History')}
                    </h2>
                    <ActivityTimeline entries={request.timeline} />
                </aside>
            </div>
        </>
    );
}

RequestShow.layout = {
    breadcrumbs: [{ title: 'Requests', href: RequestController.index.url() }],
};
