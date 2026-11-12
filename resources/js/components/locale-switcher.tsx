import { Languages } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { usePreferences } from '@/hooks/use-preferences';
import { changeLocale, useTranslations } from '@/hooks/use-translations';

// Each language is named in itself, so it can be found from any language.
const NATIVE_NAMES: Record<string, { name: string; lang: string }> = {
    ar: { name: 'العربية', lang: 'ar' },
    en: { name: 'English', lang: 'en' },
};

export default function LocaleSwitcher() {
    const { t, locale, locales } = useTranslations();
    const { saved } = usePreferences();

    if (locales.length < 2) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="sm" data-test="locale-switcher">
                    <Languages />
                    <span lang={NATIVE_NAMES[locale]?.lang ?? locale}>
                        {NATIVE_NAMES[locale]?.name ?? locale}
                    </span>
                    <span className="sr-only">{t('Language')}</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuRadioGroup
                    value={locale}
                    onValueChange={(value) => {
                        if (value !== locale) {
                            changeLocale(value, saved);
                        }
                    }}
                >
                    {locales.map((code) => (
                        <DropdownMenuRadioItem
                            key={code}
                            value={code}
                            lang={NATIVE_NAMES[code]?.lang ?? code}
                        >
                            {NATIVE_NAMES[code]?.name ?? code}
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
