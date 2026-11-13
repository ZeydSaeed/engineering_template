import { Form } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { ReactNode } from 'react';
import FormField from '@/components/form-field';
import PasswordInput from '@/components/password-input';
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
import { useTranslations } from '@/hooks/use-translations';
import type { RouteFormDefinition } from '@/wayfinder';

/**
 * Asks before a sensitive action (improvement #22). The action runs only
 * from the dialog's confirm button, as a normal form submission, so CSRF
 * protection and the server's authorisation apply unchanged.
 *
 * With `requirePassword`, the dialog asks for the current password and
 * sends it as `password`. The client cannot enforce this: the route's form
 * request must validate it with the `current_password` rule (as
 * ProfileDeleteRequest does), or the route must use the `password.confirm`
 * middleware.
 */
export default function ConfirmAction({
    trigger,
    title,
    description,
    confirmLabel,
    form,
    tone = 'destructive',
    requirePassword = false,
    fields = {},
    onSuccess,
}: {
    trigger: ReactNode;
    title: string;
    description: string;
    confirmLabel: string;
    form: RouteFormDefinition<'get' | 'post'>;
    tone?: 'destructive' | 'default';
    requirePassword?: boolean;
    /** Values sent with the confirmation, as hidden fields. */
    fields?: Record<string, string>;
    onSuccess?: () => void;
}) {
    const { t } = useTranslations();
    const [open, setOpen] = useState(false);
    const password = useRef<HTMLInputElement>(null);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent data-slot="confirm-action">
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>{description}</DialogDescription>

                <Form
                    {...form}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    onError={() => password.current?.focus()}
                    onSuccess={() => {
                        setOpen(false);
                        onSuccess?.();
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors, resetAndClearErrors }) => (
                        <>
                            {Object.entries(fields).map(([name, value]) => (
                                <input
                                    key={name}
                                    type="hidden"
                                    name={name}
                                    value={value}
                                />
                            ))}
                            {/* The server's answer about anything but the
                                password (someone acted first, a rule
                                refused it), announced in the dialog. */}
                            {Object.entries(errors)
                                .filter(([name]) => name !== 'password')
                                .slice(0, 1)
                                .map(([name, message]) => (
                                    <p
                                        key={name}
                                        role="alert"
                                        className="rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive"
                                        data-slot="confirm-action-error"
                                    >
                                        {message}
                                    </p>
                                ))}
                            {requirePassword && (
                                <FormField
                                    name="password"
                                    label={t('Password')}
                                    error={errors.password}
                                    required
                                >
                                    {(control) => (
                                        <PasswordInput
                                            {...control}
                                            ref={password}
                                            autoComplete="current-password"
                                        />
                                    )}
                                </FormField>
                            )}

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={() => resetAndClearErrors()}
                                    >
                                        {t('Cancel')}
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant={
                                        tone === 'destructive'
                                            ? 'destructive'
                                            : 'default'
                                    }
                                    disabled={processing}
                                    data-test="confirm-action-button"
                                >
                                    {confirmLabel}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
