/**
 * Shared vocabulary for the Projects Management workspace.
 *
 * Project and task statuses are the values the EXISTING projects/tasks tables
 * already store — they are re-declared here (not invented) so the new pages
 * label them identically to the old ones.
 */

export const PROJECT_STATUSES = [
    { value: 'not_started', label: 'Not started', color: 'default' },
    { value: 'progress', label: 'In progress', color: 'processing' },
    { value: 'on_hold', label: 'On hold', color: 'warning' },
    { value: 'completed', label: 'Completed', color: 'success' },
    { value: 'cancelled', label: 'Cancelled', color: 'error' },
];

export const TASK_STATUSES = [
    { value: 'not_started', label: 'Not started', color: 'default' },
    { value: 'progress', label: 'In progress', color: 'processing' },
    { value: 'completed', label: 'Completed', color: 'success' },
    { value: 'cancelled', label: 'Cancelled', color: 'error' },
];

export const TASK_PRIORITIES = [
    { value: 'low', label: 'Low', color: 'default' },
    { value: 'medium', label: 'Medium', color: 'processing' },
    { value: 'high', label: 'High', color: 'warning' },
    { value: 'urgent', label: 'Urgent', color: 'error' },
];

export const MILESTONE_STATUSES = [
    { value: 'pending', label: 'Pending', color: 'default' },
    { value: 'in_progress', label: 'In progress', color: 'processing' },
    { value: 'completed', label: 'Completed', color: 'success' },
    { value: 'delayed', label: 'Delayed', color: 'error' },
];

/** Board column order — left to right is the natural flow of work. */
export const BOARD_COLUMNS = [
    { key: 'not_started', label: 'Not started', color: '#8c8c8c' },
    { key: 'progress', label: 'In progress', color: '#1677ff' },
    { key: 'completed', label: 'Completed', color: '#16a34a' },
    { key: 'cancelled', label: 'Cancelled', color: '#ff4d4f' },
];

export function optionOf(list, value) {
    return list.find(o => o.value === value) || { value, label: value || '—', color: 'default' };
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
    if (days < 0) return { text: `${Math.abs(days)}d late`, color: 'error' };
    if (days === 0) return { text: 'Due today', color: 'warning' };
    if (days <= 7) return { text: `in ${days}d`, color: 'warning' };
    return { text: `in ${days}d`, color: 'default' };
}
