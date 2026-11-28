/**
 * Leave types on screen (2026-11-28, daily attendance + absence calendar): the install-wide rows are in English, so the
 * standard ones get their Spanish here (same map as AttendanceSheetController::typeLabel); each type has its colour.
 */
const ES = {
    'annual leave': 'Vacaciones', 'sick leave': 'Enfermedad', 'personal leave': 'Asunto personal',
    'maternity leave': 'Maternidad', 'paternity leave': 'Paternidad', 'unpaid leave': 'Sin goce de haber',
};
const COLORS = { 'annual leave': '#16a34a', 'sick leave': '#ea580c', 'personal leave': '#2563eb' };
const EXTRA = ['#7c3aed', '#0891b2', '#c026d3', '#ca8a04', '#4f46e5'];
export const ABSENT_COLOR = '#dc2626';

export function leaveTypeLabel(title, es) {
    const k = String(title || '').trim().toLowerCase();
    return es ? (ES[k] || title) : title;
}

export function leaveTypeColor(types, id) {
    if (!id) return ABSENT_COLOR;
    const i = types.findIndex(t => t.id === id);
    const k = String(types[i]?.title || '').trim().toLowerCase();
    return COLORS[k] || EXTRA[Math.max(i, 0) % EXTRA.length];
}
