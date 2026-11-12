import { Head } from '@inertiajs/react';
import UserInvitationController from '@/actions/App/Http/Controllers/Admin/UserInvitationController';
import { roleLabel } from '@/components/activity-timeline';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import MultiStepForm from '@/components/multi-step-form';
import { Input } from '@/components/ui/input';
import { useTranslations } from '@/hooks/use-translations';
import { index as usersIndex } from '@/routes/admin/users';
import { invite } from '@/routes/admin/users';

/**
 * Inviting a person in three steps (improvement #6; ADR-0027): details,
 * role, review. Each step is checked by the server before the next.
 */
export default function InviteUser({ roles }: { roles: string[] }) {
    const { t } = useTranslations();

    return (
        <>
            <Head title={t('Invite a person')} />
            <div className="flex max-w-xl flex-col gap-6 p-4">
                <Heading
                    title={t('Invite a person')}
                    description={t(
                        'They receive an email to set their password, then set up a second factor on first sign-in.',
                    )}
                />

                <MultiStepForm
                    form={UserInvitationController.store.form()}
                    submitLabel={t('Send invitation')}
                    steps={[
                        {
                            id: 'details',
                            title: t('Details'),
                            fields: ['name', 'email'],
                            render: ({ errors, validate }) => (
                                <>
                                    <FormField
                                        name="name"
                                        label={t('Full name')}
                                        error={errors.name}
                                        required
                                        onValidate={() => validate('name')}
                                    >
                                        {(control) => (
                                            <Input
                                                {...control}
                                                autoComplete="off"
                                            />
                                        )}
                                    </FormField>
                                    <FormField
                                        name="email"
                                        label={t('Email address')}
                                        error={errors.email}
                                        required
                                        onValidate={() => validate('email')}
                                    >
                                        {(control) => (
                                            <Input
                                                {...control}
                                                type="email"
                                                dir="ltr"
                                                autoComplete="off"
                                            />
                                        )}
                                    </FormField>
                                </>
                            ),
                        },
                        {
                            id: 'role',
                            title: t('Role'),
                            fields: ['role'],
                            render: ({ errors }) => (
                                <fieldset
                                    className="space-y-3"
                                    aria-describedby={
                                        errors.role ? 'role-error' : undefined
                                    }
                                >
                                    <legend className="mb-2 text-sm text-muted-foreground">
                                        {t('A role can also be granted later.')}
                                    </legend>
                                    {['', ...roles].map((role) => (
                                        <label
                                            key={role || 'none'}
                                            className="flex items-center gap-3 rounded-lg border px-3 py-2 text-sm has-[:checked]:border-primary has-[:checked]:bg-accent"
                                        >
                                            <input
                                                type="radio"
                                                name="role"
                                                value={role}
                                                defaultChecked={role === ''}
                                                className="size-4 accent-primary"
                                            />
                                            {role === ''
                                                ? t('No role')
                                                : roleLabel(t, role)}
                                        </label>
                                    ))}
                                    <p
                                        id="role-error"
                                        aria-live="polite"
                                        className="text-sm text-destructive-foreground empty:hidden"
                                    >
                                        {errors.role}
                                    </p>
                                </fieldset>
                            ),
                        },
                        {
                            id: 'review',
                            title: t('Review'),
                            fields: [],
                            render: ({ getData }) => {
                                const data = getData() as Record<
                                    string,
                                    string
                                >;

                                return (
                                    <dl
                                        className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm"
                                        data-slot="invite-review"
                                    >
                                        <dt className="text-muted-foreground">
                                            {t('Full name')}
                                        </dt>
                                        <dd>{data.name}</dd>
                                        <dt className="text-muted-foreground">
                                            {t('Email address')}
                                        </dt>
                                        <dd dir="ltr" className="text-start">
                                            {data.email}
                                        </dd>
                                        <dt className="text-muted-foreground">
                                            {t('Role')}
                                        </dt>
                                        <dd>
                                            {data.role
                                                ? roleLabel(t, data.role)
                                                : t('No role')}
                                        </dd>
                                    </dl>
                                );
                            },
                        },
                    ]}
                />
            </div>
        </>
    );
}

InviteUser.layout = {
    breadcrumbs: [
        { title: 'Users', href: usersIndex() },
        { title: 'Invite a person', href: invite() },
    ],
};
