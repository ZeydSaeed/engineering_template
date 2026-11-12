import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import ConfirmAction from '@/components/confirm-action';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';

export default function DeleteUser() {
    const { t } = useTranslations();

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title={t('Delete account')}
                description={t('Delete your account and all of its resources')}
            />
            <div className="space-y-4 rounded-lg border border-destructive/30 bg-destructive/5 p-4">
                <div className="relative space-y-0.5 text-destructive-foreground">
                    <p className="font-medium">{t('Warning')}</p>
                    <p className="text-sm">
                        {t(
                            'Please proceed with caution, this cannot be undone.',
                        )}
                    </p>
                </div>

                {/* ProfileDeleteRequest checks the password (current_password). */}
                <ConfirmAction
                    trigger={
                        <Button
                            variant="destructive"
                            data-test="delete-user-button"
                        >
                            {t('Delete account')}
                        </Button>
                    }
                    title={t('Are you sure you want to delete your account?')}
                    description={t(
                        'Once your account is deleted, all of its resources and data will also be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.',
                    )}
                    confirmLabel={t('Delete account')}
                    form={ProfileController.destroy.form()}
                    requirePassword
                />
            </div>
        </div>
    );
}
