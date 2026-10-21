import { useI18n } from 'vue-i18n';

/**
 * A page's own texts in the active language (same idea as the School module's
 * useSchoolText): the English text is the key, `es` is the page's Spanish map.
 * s('Localization') -> 'Localización' in Spanish, the English otherwise.
 * Placeholders: s('{n} items', { n: 3 }). Reactive (reads the locale per call).
 */
export function useLocalText(es = {}) {
    const { locale } = useI18n();

    function s(text, params) {
        let out = String(text ?? '');
        if (String(locale.value || '').startsWith('es') && es[out] !== undefined) out = es[out];
        if (params) out = out.replace(/\{(\w+)\}/g, (m, k) => (params[k] !== undefined && params[k] !== null ? String(params[k]) : m));
        return out;
    }

    return { s };
}
