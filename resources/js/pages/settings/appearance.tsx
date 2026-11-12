import { Head } from '@inertiajs/react';
import { useState } from 'react';
import AppearanceTabs from '@/components/appearance-tabs';
import Heading from '@/components/heading';
import HelpTip from '@/components/help-tip';
import LocaleSwitcher from '@/components/locale-switcher';
import { Checkbox } from '@/components/ui/checkbox';
import { savePreferences, usePreferences } from '@/hooks/use-preferences';
import type { WorkspacePreferences } from '@/hooks/use-preferences';
import { useTranslations } from '@/hooks/use-translations';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    const { t } = useTranslations();
    const preferences = usePreferences();
    // Shown at once; saving follows (ADR-0028).
    const [density, setDensityState] = useState(preferences.density);
    const [shortcuts, setShortcuts] = useState(preferences.shortcuts);

    const setDensity = (density: WorkspacePreferences['density']) => {
        setDensityState(density);
        // Applied at once; the server copy follows the person (ADR-0028).
        document.documentElement.dataset.density = density;
        savePreferences({ density });
    };

    return (
        <>
            <Head title={t('Appearance settings')} />

            <h1 className="sr-only">{t('Appearance settings')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('Appearance settings')}
                    description={t(
                        'Update the appearance settings for your account',
                    )}
                />
                <AppearanceTabs />
            </div>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('Language')}
                    description={t(
                        'The language and direction of the interface',
                    )}
                />
                <LocaleSwitcher />
            </div>

            <fieldset className="space-y-4" data-test="density">
                <legend className="mb-1 text-base font-medium">
                    {t('Density')}
                </legend>
                <div className="flex flex-wrap gap-3">
                    {(['comfortable', 'compact'] as const).map((option) => (
                        <label
                            key={option}
                            className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm has-[:checked]:border-primary has-[:checked]:bg-accent"
                        >
                            <input
                                type="radio"
                                name="density"
                                value={option}
                                checked={density === option}
                                onChange={() => setDensity(option)}
                                className="size-4 accent-primary"
                            />
                            {option === 'comfortable'
                                ? t('Comfortable')
                                : t('Compact')}
                        </label>
                    ))}
                </div>
                <HelpTip topic={t('Density')}>
                    {t(
                        'Compact shows more rows in tables and lists by using less space around them. Buttons and links keep the same size.',
                    )}
                </HelpTip>
            </fieldset>

            <div className="space-y-4">
                <Heading
                    variant="small"
                    title={t('Keyboard shortcuts')}
                    description={t(
                        'Press ? anywhere to see them. Ctrl+K (⌘K) always opens search.',
                    )}
                />
                <div className="flex items-center gap-3">
                    <Checkbox
                        id="single-key-shortcuts"
                        checked={shortcuts}
                        onCheckedChange={(checked) => {
                            setShortcuts(checked === true);
                            savePreferences({ shortcuts: checked === true });
                        }}
                        data-test="shortcuts-toggle"
                    />
                    <label htmlFor="single-key-shortcuts" className="text-sm">
                        {t('Single-key shortcuts (such as ? and g then d)')}
                    </label>
                </div>
                <HelpTip topic={t('Keyboard shortcuts')}>
                    {t(
                        'Turn single-key shortcuts off if you use speech input or press keys by accident; shortcuts with Ctrl or ⌘ keep working.',
                    )}
                </HelpTip>
            </div>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: 'Appearance settings',
            href: editAppearance(),
        },
    ],
};
