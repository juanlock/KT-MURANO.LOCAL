/**
 * Shared vocabulary for the school module: the enum values the API stores, the
 * label each shows and the antd tag colour it wears. Every school page reads
 * these, so a status can never look one way in a list and another in a report.
 */

export const GENDERS = [
    { value: 'male', label: 'Male' },
    { value: 'female', label: 'Female' },
    { value: 'other', label: 'Other' },
];

export const STUDENT_STATUSES = [
    { value: 'active', label: 'Active', color: 'success' },
    { value: 'inactive', label: 'Inactive', color: 'default' },
    { value: 'graduated', label: 'Graduated', color: 'processing' },
    { value: 'transferred', label: 'Transferred', color: 'warning' },
    { value: 'expelled', label: 'Expelled', color: 'error' },
];

export const ENROLLMENT_STATUSES = [
    { value: 'active', label: 'Active', color: 'success' },
    { value: 'promoted', label: 'Promoted', color: 'processing' },
    { value: 'repeated', label: 'Repeated', color: 'warning' },
    { value: 'transferred', label: 'Transferred', color: 'default' },
    { value: 'left', label: 'Left', color: 'error' },
];

export const ATTENDANCE_STATUSES = [
    { value: 'present', label: 'Present', color: 'success', short: 'P' },
    { value: 'absent', label: 'Absent', color: 'error', short: 'A' },
    { value: 'late', label: 'Late', color: 'warning', short: 'L' },
    { value: 'excused', label: 'Excused', color: 'processing', short: 'E' },
    { value: 'half_day', label: 'Half day', color: 'default', short: 'H' },
];

export const SUBJECT_TYPES = [
    { value: 'core', label: 'Core', color: 'blue' },
    { value: 'elective', label: 'Elective', color: 'purple' },
    { value: 'optional', label: 'Optional', color: 'default' },
];

export const EXAM_TERMS = [
    { value: 'term_1', label: 'Term 1' },
    { value: 'term_2', label: 'Term 2' },
    { value: 'term_3', label: 'Term 3' },
    { value: 'final', label: 'Final' },
    { value: 'other', label: 'Other' },
];

export const EXAM_STATUSES = [
    { value: 'draft', label: 'Draft', color: 'default' },
    { value: 'scheduled', label: 'Scheduled', color: 'processing' },
    { value: 'ongoing', label: 'Ongoing', color: 'warning' },
    { value: 'completed', label: 'Completed', color: 'cyan' },
    { value: 'published', label: 'Published', color: 'success' },
];

export const FEE_FREQUENCIES = [
    { value: 'once', label: 'One-off' },
    { value: 'monthly', label: 'Monthly' },
    { value: 'termly', label: 'Termly' },
    { value: 'yearly', label: 'Yearly' },
];

export const INVOICE_STATUSES = [
    { value: 'draft', label: 'Draft', color: 'default' },
    { value: 'unpaid', label: 'Unpaid', color: 'error' },
    { value: 'partial', label: 'Partly paid', color: 'warning' },
    { value: 'paid', label: 'Paid', color: 'success' },
    { value: 'cancelled', label: 'Cancelled', color: 'default' },
];

export const PAYMENT_METHODS = [
    { value: 'cash', label: 'Cash' },
    { value: 'card', label: 'Card' },
    { value: 'bank_transfer', label: 'Bank transfer' },
    { value: 'cheque', label: 'Cheque' },
    { value: 'mobile_money', label: 'Mobile money' },
    { value: 'other', label: 'Other' },
];

export const WEEK_DAYS = [
    { value: 'mon', label: 'Monday', short: 'Mon' },
    { value: 'tue', label: 'Tuesday', short: 'Tue' },
    { value: 'wed', label: 'Wednesday', short: 'Wed' },
    { value: 'thu', label: 'Thursday', short: 'Thu' },
    { value: 'fri', label: 'Friday', short: 'Fri' },
    { value: 'sat', label: 'Saturday', short: 'Sat' },
    { value: 'sun', label: 'Sunday', short: 'Sun' },
];

/** Look one up, falling back to the raw value so nothing renders blank. */
export function optionOf(list, value) {
    return list.find(o => o.value === value) || { value, label: value || '—', color: 'default' };
}

export function labelOf(list, value) {
    return optionOf(list, value).label;
}

/**
 * Colour for a grade, matching the backend's default scale in
 * ExamResult::gradeFor(). If a school changes that scale, change this too —
 * they are two halves of one decision.
 */
export function gradeColor(grade) {
    if (!grade) return 'default';
    if (grade === 'AB') return 'default';
    if (grade.startsWith('A')) return 'success';
    if (grade.startsWith('B')) return 'cyan';
    if (grade === 'C') return 'processing';
    if (grade === 'D') return 'warning';
    return 'error';
}

/** Attendance / collection rates share one traffic-light reading. */
export function rateColor(rate, { good = 90, fair = 75 } = {}) {
    if (rate === null || rate === undefined) return 'default';
    if (rate >= good) return 'success';
    if (rate >= fair) return 'warning';
    return 'error';
}
