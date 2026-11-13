import { Form, Head } from '@inertiajs/react';
import { useRef } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/heading';
import FormField from '@/components/form-field';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { edit } from '@/routes/security';
import type { Props as ManagePasskeysProps } from '@/components/manage-passkeys';
import ManagePasskeys from '@/components/manage-passkeys';
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import ManageTwoFactor from '@/components/manage-two-factor';
import { useTranslations } from '@/hooks/use-translations';

// oxfmt-ignore
type Props = {
    passwordRules: string;
} & ManagePasskeysProps &
    ManageTwoFactorProps;

export default function Security(props: Props) {
    const { t } = useTranslations();

    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    return (
        <>
            <Head title={t('Security settings')} />

            <h1 className="sr-only">{t('Security settings')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('Update password')}
                    description={t(
                        'Ensure your account is using a long, random password to stay secure',
                    )}
                />

                <Form
                    {...SecurityController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    resetOnError={[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]}
                    resetOnSuccess
                    onError={(errors) => {
                        if (errors.password) {
                            passwordInput.current?.focus();
                        }

                        if (errors.current_password) {
                            currentPasswordInput.current?.focus();
                        }
                    }}
                    className="space-y-6"
                >
                    {({ errors, processing, validate }) => (
                        <>
                            {/* Checked on submit only: a live check would
                                let anyone with the session guess it. */}
                            <FormField
                                name="current_password"
                                label={t('Current password')}
                                error={errors.current_password}
                                required
                            >
                                {(control) => (
                                    <PasswordInput
                                        {...control}
                                        ref={currentPasswordInput}
                                        autoComplete="current-password"
                                    />
                                )}
                            </FormField>

                            <FormField
                                name="password"
                                label={t('New password')}
                                error={errors.password}
                                required
                                onValidate={() => validate('password')}
                            >
                                {(control) => (
                                    <PasswordInput
                                        {...control}
                                        ref={passwordInput}
                                        autoComplete="new-password"
                                        passwordrules={props.passwordRules}
                                    />
                                )}
                            </FormField>

                            <FormField
                                name="password_confirmation"
                                label={t('Confirm password')}
                                error={errors.password_confirmation}
                                required
                                onValidate={() =>
                                    validate('password_confirmation')
                                }
                            >
                                {(control) => (
                                    <PasswordInput
                                        {...control}
                                        autoComplete="new-password"
                                        passwordrules={props.passwordRules}
                                    />
                                )}
                            </FormField>

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-password-button"
                                >
                                    {t('Save')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            <ManageTwoFactor
                canManageTwoFactor={props.canManageTwoFactor}
                requiresConfirmation={props.requiresConfirmation}
                twoFactorEnabled={props.twoFactorEnabled}
            />

            <ManagePasskeys
                canManagePasskeys={props.canManagePasskeys}
                passkeys={props.passkeys}
            />
        </>
    );
}

Security.layout = {
    breadcrumbs: [
        {
            title: 'Security settings',
            href: edit(),
        },
    ],
};
