/**
 * HRM shared vocabulary. Employment types and leave statuses are backend
 * values; labels are hardcoded English exactly like the legacy selects.
 */

export const EMPLOYMENT_TYPES = [
    { value: 'full_time', label: 'Full-time' },
    { value: 'part_time', label: 'Part-time' },
    { value: 'self_employed', label: 'Self-employed' },
    { value: 'freelance', label: 'Freelance' },
    { value: 'contract', label: 'Contract' },
    { value: 'internship', label: 'Internship' },
    { value: 'apprenticeship', label: 'Apprenticeship' },
    { value: 'seasonal', label: 'Seasonal' },
];

export const GENDERS = [
    { value: 'male', label: 'Male' },
    { value: 'female', label: 'Female' },
];

export const LEAVE_STATUSES = [
    { value: 'approved', label: 'Approved' },
    { value: 'pending', label: 'Pending' },
    { value: 'rejected', label: 'Rejected' },
];

const LEAVE_STATUS_COLORS = { approved: 'green', pending: 'orange', rejected: 'red' };

export function leaveStatusColor(status) {
    return LEAVE_STATUS_COLORS[status] || 'default';
}

export function leaveStatusLabel(status) {
    const found = LEAVE_STATUSES.find(s => s.value === status);
    return found ? found.label : (status || '—');
}
