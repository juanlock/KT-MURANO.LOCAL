import { useI18n } from 'vue-i18n';
import COMMON from './common.es.js';

/**
 * School module texts in the active language (2026-10-20: a Spanish school
 * showed English buttons and titles). The English text itself is the key:
 * s('Take register') -> 'Pasar lista' when the app is in Spanish, the same
 * English otherwise. Each page passes its own Spanish map (lang/<Page>.es.js)
 * on top of the module's common words (lang/common.es.js).
 * Placeholders: s('{n} absent', { n: 3 }).
 * Reactive: reads the locale on every call, so templates follow a language switch.
 */
export function useSchoolText(pageEs = {}) {
    const { locale } = useI18n();
    const dict = { ...COMMON, ...pageEs };

    function s(text, params) {
        let out = String(text ?? '');
        if (String(locale.value || '').startsWith('es') && dict[out] !== undefined) out = dict[out];
        if (params) out = out.replace(/\{(\w+)\}/g, (m, k) => (params[k] !== undefined && params[k] !== null ? String(params[k]) : m));
        return out;
    }

    return { s, isEs: () => String(locale.value || '').startsWith('es') };
}
