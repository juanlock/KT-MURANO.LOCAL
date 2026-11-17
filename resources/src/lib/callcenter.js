// Call center screens (2026-11-17): what they share.
import { useUiStore } from '../stores/ui';

/** tr('English', 'Español') in the screen's language. */
export function useTr() {
    const ui = useUiStore();
    return (en, es) => (ui.locale === 'es' ? es : en);
}

/** 125 -> "2:05", 3725 -> "1:02:05". */
export function duration(sec) {
    const s = Math.max(0, Math.round(Number(sec) || 0));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const r = String(s % 60).padStart(2, '0');
    return h ? `${h}:${String(m).padStart(2, '0')}:${r}` : `${m}:${r}`;
}

/** Hours with one decimal: 7.5 h. */
export const hours = sec => `${(Math.round((Number(sec) || 0) / 360) / 10).toLocaleString()} h`;

export const RESULT_COLORS = {
    sale: 'green', interested: 'cyan', callback: 'blue', not_interested: 'default', complaint: 'magenta',
    no_answer: 'orange', busy: 'orange', voicemail: 'gold', wrong_number: 'red', do_not_call: 'red',
};

/** The error message the server sent, else a fallback. */
export function errText(e, fallback) {
    const d = e?.data;
    if (d?.errors) return Object.values(d.errors).flat().join(' ');
    return d?.message || fallback;
}
