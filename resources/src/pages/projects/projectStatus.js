/**
 * Project status vocabulary — values and English labels are hardcoded in the
 * legacy pages too (no i18n keys). NOTE the short values: `progress`, `hold`.
 */
export const PROJECT_STATUSES = [
    { value: 'not_started', label: 'Not Started' },
    { value: 'progress', label: 'In Progress' },
    { value: 'hold', label: 'On Hold' },
    { value: 'completed', label: 'Completed' },
    { value: 'cancelled', label: 'Cancelled' },
];

export function projectStatusLabel(v) {
    return PROJECT_STATUSES.find(s => s.value === v)?.label || v;
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
