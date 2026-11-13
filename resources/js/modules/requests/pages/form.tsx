import { Form, Head, Link } from '@inertiajs/react';
import RequestController from '@/actions/App/Modules/Requests/Http/RequestController';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useTranslations } from '@/hooks/use-translations';
import type { FullRequest } from '../types';

/**
 * A new request, or a change to a draft (ADR-0033). Fields are checked by
 * the server while typing (Precognition). A change sends the version the
 * form was opened with, so a change made meanwhile elsewhere is reported
 * instead of overwritten.
 *
 * The button is disabled only while sending, never while a field is being
 * checked: pressing it moves focus off the last field, which starts a check,
 * and disabling then would swallow the press (the server checks every field
 * on saving anyway).
 */
export default function RequestForm({
    request,
}: {
    request: FullRequest | null;
}) {
    const { t } = useTranslations();
    const editing = request !== null;
    const title = editing ? t('Change the draft') : t('New request');
    const action = editing
        ? RequestController.update.form(request.uuid)
        : RequestController.store.form();

    return (
        <>
            <Head title={title} />
            <div className="flex max-w-2xl flex-col gap-6 p-4">
                <Heading
                    title={title}
                    description={t(
                        'Say what you need and why. You can add files after saving the draft, and submit it when it is ready.',
                    )}
                />

                <Form
                    {...action}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                    data-test="request-form"
                >
                    {({ processing, errors, validate }) => (
                        <>
                            {editing && (
                                <input
                                    type="hidden"
                                    name="lock_version"
                                    value={request.lock_version}
                                />
                            )}
                            {errors.lock_version && (
                                <p
                                    role="alert"
                                    className="rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive"
                                    data-test="stale-form"
                                >
                                    {errors.lock_version}
                                </p>
                            )}

                            <FormField
                                name="title"
                                label={t('Title')}
                                error={errors.title}
                                required
                                onValidate={() => validate('title')}
                            >
                                {(control) => (
                                    <Input
                                        {...control}
                                        defaultValue={request?.title}
                                        maxLength={150}
                                        dir="auto"
                                    />
                                )}
                            </FormField>

                            <FormField
                                name="details"
                                label={t('Details')}
                                description={t(
                                    'What is needed, for whom, and why.',
                                )}
                                error={errors.details}
                                required
                                onValidate={() => validate('details')}
                            >
                                {(control) => (
                                    <Textarea
                                        {...control}
                                        defaultValue={request?.details}
                                        maxLength={5000}
                                        rows={6}
                                        dir="auto"
                                    />
                                )}
                            </FormField>

                            <FormField
                                name="amount"
                                label={t('Amount')}
                                description={t(
                                    'Optional. Numbers only, up to two decimals.',
                                )}
                                error={errors.amount}
                                onValidate={() => validate('amount')}
                            >
                                {(control) => (
                                    <Input
                                        {...control}
                                        defaultValue={request?.amount ?? ''}
                                        inputMode="decimal"
                                        dir="ltr"
                                        className="max-w-48 tabular-nums"
                                    />
                                )}
                            </FormField>

                            <div className="flex flex-wrap items-center gap-3">
                                <Button
                                    disabled={processing}
                                    data-test="save-request"
                                >
                                    {t('Save draft')}
                                </Button>
                                <Button variant="ghost" asChild>
                                    <Link
                                        href={
                                            editing
                                                ? RequestController.show.url(
                                                      request.uuid,
                                                  )
                                                : RequestController.index.url()
                                        }
                                    >
                                        {t('Cancel')}
                                    </Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

RequestForm.layout = {
    breadcrumbs: [{ title: 'Requests', href: RequestController.index.url() }],
};
