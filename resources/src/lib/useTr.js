import { useUiStore } from '../stores/ui';

/**
 * tr('English', 'Español') -> the text in the language the user picked (ui.locale). For screens whose texts were written by
 * hand in one language (rule: "no text written by hand in one language", 2026-11-05). Use inside setup():
 *   const tr = useTr();   // template: {{ tr('Save', 'Guardar') }}
 */
export function useTr() {
    const ui = useUiStore();
    return (en, es) => (ui.locale === 'es' ? es : en);
}

/** Outside setup (plain .js helpers): the same, reading the store when called. */
export function tr(en, es) {
    try {
        return useUiStore().locale === 'es' ? es : en;
    } catch (e) {
        return en;
    }
}
