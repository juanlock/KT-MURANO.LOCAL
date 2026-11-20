import { tr } from '../../lib/useTr';

/**
 * Shared vocabulary for the Projects Management workspace.
 *
 * Project and task statuses are the values the EXISTING projects/tasks tables
 * already store — they are re-declared here (not invented) so the new pages
 * label them identically to the old ones.
 *
 * Each list is a function returning [{ value, label, color }] with the label in
 * the user's language: call it where it is used (computed / template), never
 * once at import time.
 */

const translated = list => () => list.map(({ en, es, ...rest }) => ({ ...rest, label: tr(en, es) }));

export const PROJECT_STATUSES = translated([
    { value: 'not_started', en: 'Not started', es: 'Sin iniciar', color: 'default' },
    { value: 'progress', en: 'In progress', es: 'En progreso', color: 'processing' },
    { value: 'on_hold', en: 'On hold', es: 'En pausa', color: 'warning' },
    { value: 'completed', en: 'Completed', es: 'Completado', color: 'success' },
    { value: 'cancelled', en: 'Cancelled', es: 'Cancelado', color: 'error' },
]);

export const TASK_STATUSES = translated([
    { value: 'not_started', en: 'Not started', es: 'Sin iniciar', color: 'default' },
    { value: 'progress', en: 'In progress', es: 'En progreso', color: 'processing' },
    { value: 'completed', en: 'Completed', es: 'Completado', color: 'success' },
    { value: 'cancelled', en: 'Cancelled', es: 'Cancelado', color: 'error' },
]);

export const TASK_PRIORITIES = translated([
    { value: 'low', en: 'Low', es: 'Baja', color: 'default' },
    { value: 'medium', en: 'Medium', es: 'Media', color: 'processing' },
    { value: 'high', en: 'High', es: 'Alta', color: 'warning' },
    { value: 'urgent', en: 'Urgent', es: 'Urgente', color: 'error' },
]);

export const MILESTONE_STATUSES = translated([
    { value: 'pending', en: 'Pending', es: 'Pendiente', color: 'default' },
    { value: 'in_progress', en: 'In progress', es: 'En progreso', color: 'processing' },
    { value: 'completed', en: 'Completed', es: 'Completado', color: 'success' },
    { value: 'delayed', en: 'Delayed', es: 'Retrasado', color: 'error' },
]);

/** Board column order — left to right is the natural flow of work. */
export const BOARD_COLUMNS = translated([
    { key: 'not_started', en: 'Not started', es: 'Sin iniciar', color: '#8c8c8c' },
    { key: 'progress', en: 'In progress', es: 'En progreso', color: '#1677ff' },
    { key: 'completed', en: 'Completed', es: 'Completado', color: '#16a34a' },
    { key: 'cancelled', en: 'Cancelled', es: 'Cancelado', color: '#ff4d4f' },
]);

/** `list` = one of the lists above (the function itself) or an already built array. */
export function optionOf(list, value) {
    const options = typeof list === 'function' ? list() : list;
    return options.find(o => o.value === value) || { value, label: value || '—', color: 'default' };
}

export function labelOf(list, value) {
    return optionOf(list, value).label;
}

/** Progress bar colour: green when done, amber mid-way, grey at the start. */
export function progressColor(percent) {
    if (percent >= 100) return '#16a34a';
    if (percent >= 50) return '#6d28d9';
    if (percent > 0) return '#faad14';
    return '#d9d9d9';
}

/** "in 3 days" / "5 days late" — null when there is no deadline to speak of. */
export function dueLabel(days) {
    if (days === null || days === undefined) return null;
    if (days < 0) return { text: tr(`${Math.abs(days)}d late`, `${Math.abs(days)} d de atraso`), color: 'error' };
    if (days === 0) return { text: tr('Due today', 'Vence hoy'), color: 'warning' };
    if (days <= 7) return { text: tr(`in ${days}d`, `en ${days} d`), color: 'warning' };
    return { text: tr(`in ${days}d`, `en ${days} d`), color: 'default' };
}
