import { Form } from '@inertiajs/react';
import { CircleCheck, CircleX } from 'lucide-react';
import { useState } from 'react';
import RequestController from '@/actions/App/Modules/Requests/Http/RequestController';
import ConfirmAction from '@/components/confirm-action';
import FormField from '@/components/form-field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { useTranslations } from '@/hooks/use-translations';

/**
 * An approver's decision (ADR-0033): approve after a confirmation, or
 * reject with a reason the requester will read. Both are ordinary form
 * submissions, so CSRF protection and the server's checks apply; the
 * server re-checks everything under the row lock and says if someone else
 * decided first.
 */
export default function DecisionPanel({
    uuid,
    title,
}: {
    uuid: string;
    title: string;
}) {
    const { t } = useTranslations();
    const [rejecting, setRejecting] = useState(false);

    return (
        <section
            aria-labelledby="decision-heading"
            className="flex flex-col gap-3 rounded-xl border p-4"
            data-slot="decision-panel"
        >
            <h2 id="decision-heading" className="font-semibold">
                {t('Your decision')}
            </h2>
            <p className="text-sm text-muted-foreground">
                {t(
                    'The requester is told at once. A decision cannot be changed afterwards.',
                )}
            </p>
            <div className="flex flex-wrap gap-3">
                <ConfirmAction
                    trigger={
                        <Button data-test="approve-request">
                            <CircleCheck aria-hidden />
                            {t('Approve')}
                        </Button>
                    }
                    title={t('Approve this request?')}
                    description={t(':title will be approved.', { title })}
                    confirmLabel={t('Approve')}
                    tone="default"
                    form={RequestController.decide.form(uuid)}
                    fields={{ decision: 'approve' }}
                />

                <Dialog open={rejecting} onOpenChange={setRejecting}>
                    <DialogTrigger asChild>
                        <Button variant="outline" data-test="reject-request">
                            <CircleX aria-hidden />
                            {t('Reject')}
                        </Button>
                    </DialogTrigger>
                    <DialogContent data-slot="reject-dialog">
                        <DialogTitle>{t('Reject this request?')}</DialogTitle>
                        <DialogDescription>
                            {t(
                                'Say why. The requester reads this with the decision.',
                            )}
                        </DialogDescription>
                        <Form
                            {...RequestController.decide.form(uuid)}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setRejecting(false)}
                            className="space-y-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="decision"
                                        value="reject"
                                    />
                                    <FormField
                                        name="note"
                                        label={t('Reason')}
                                        error={errors.note ?? errors.decision}
                                        required
                                    >
                                        {(control) => (
                                            <Textarea
                                                {...control}
                                                maxLength={1000}
                                                rows={4}
                                                dir="auto"
                                            />
                                        )}
                                    </FormField>
                                    <DialogFooter>
                                        <DialogClose asChild>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                            >
                                                {t('Cancel')}
                                            </Button>
                                        </DialogClose>
                                        <Button
                                            variant="destructive"
                                            disabled={processing}
                                            data-test="confirm-reject"
                                        >
                                            {t('Reject')}
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </section>
    );
}
