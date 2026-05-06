import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import cs from './locales/cs.json';
import de from './locales/de.json';
import en from './locales/en.json';
import es from './locales/es.json';
import fr from './locales/fr.json';
import hu from './locales/hu.json';
import it from './locales/it.json';
import nl from './locales/nl.json';
import ru from './locales/ru.json';

type Locale = 'en' | 'fr' | 'es' | 'it' | 'de' | 'cs' | 'nl' | 'hu' | 'ru';
type Replacements = Record<string, string | number | null | undefined>;
type Catalog = Record<string, string>;

const defaultLocale: Locale = 'en';

export const languageNames: Record<Locale, string> = {
    en: 'English',
    fr: 'Francais',
    es: 'Espanol',
    it: 'Italiano',
    de: 'Deutsch',
    cs: 'Cestina',
    nl: 'Nederlands',
    hu: 'Magyar',
    ru: 'Russkiy',
};

const translations: Record<Locale, Catalog> = { en, fr, es, it, de, cs, nl, hu, ru };

function interpolate(text: string, replacements: Replacements = {}): string {
    return text.replace(/\{(\w+)\}/g, (_, key: string) => String(replacements[key] ?? `{${key}}`));
}

function resolveLocale(value?: string | null): Locale {
    return Object.prototype.hasOwnProperty.call(translations, value || '') ? value as Locale : defaultLocale;
}

export function useI18n() {
    const page = usePage();
    const locale = computed(() => resolveLocale((page.props.auth as any)?.user?.locale || (page.props.app as any)?.locale));
    const locales = computed(() => ((page.props.app as any)?.locales || Object.keys(translations)) as Locale[]);

    const t = (key: string, replacements: Replacements = {}) => {
        const text = translations[locale.value][key] || translations[defaultLocale][key] || key;

        return interpolate(text, replacements);
    };

    const formatDate = (value?: string | null, fallback = 'Never') => value
        ? new Date(value).toLocaleString(locale.value)
        : t(fallback);

    return { t, locale, locales, languageNames, formatDate };
}
