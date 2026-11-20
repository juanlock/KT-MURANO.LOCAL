import { tr } from '../../lib/useTr';

/**
 * Project status vocabulary — values and English labels are hardcoded in the
 * legacy pages too (no i18n keys). NOTE the short values: `progress`, `hold`.
 * Labels are translated when read (tr at render time, not at import time).
 */
const STATUSES = [
    { value: 'not_started', en: 'Not Started', es: 'Sin iniciar' },
    { value: 'progress', en: 'In Progress', es: 'En progreso' },
    { value: 'hold', en: 'On Hold', es: 'En pausa' },
    { value: 'completed', en: 'Completed', es: 'Completado' },
    { value: 'cancelled', en: 'Cancelled', es: 'Cancelado' },
];

/** [{ value, label }] in the user's language — call it where it is used (computed / template). */
export function PROJECT_STATUSES() {
    return STATUSES.map(s => ({ value: s.value, label: tr(s.en, s.es) }));
}

export function projectStatusLabel(v) {
    const s = STATUSES.find(x => x.value === v);
    return s ? tr(s.en, s.es) : v;
}

export function projectStatusColor(v) {
    switch (v) {
        case 'progress': return 'processing';
        case 'completed': return 'success';
        case 'hold': return 'warning';
        case 'cancelled': return 'error';
        default: return 'default';
    }
}
