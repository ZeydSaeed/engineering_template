import { Head, Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import LocaleSwitcher from '@/components/locale-switcher';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import { dashboard, login } from '@/routes';

export default function Welcome() {
    const { auth, name } = usePage().props;
    const { t } = useTranslations();

    return (
        <>
            <Head title={t('Welcome')} />
            <div className="flex min-h-svh flex-col bg-background">
                <header className="flex items-center justify-end gap-2 p-4">
                    <LocaleSwitcher />
                </header>

                <main className="flex flex-1 flex-col items-center justify-center gap-6 px-4 pb-16 text-center">
                    <AppLogoIcon className="size-12 fill-current text-primary" />
                    <h1 className="text-2xl font-semibold">{name}</h1>
                    <p className="max-w-md text-muted-foreground">
                        {t(
                            'Requests, approvals and records in one place, in Arabic and English.',
                        )}
                    </p>

                    <Button asChild size="lg">
                        {auth.user ? (
                            <Link href={dashboard()}>{t('Dashboard')}</Link>
                        ) : (
                            <Link href={login()}>{t('Log in')}</Link>
                        )}
                    </Button>
                </main>
            </div>
        </>
    );
}
