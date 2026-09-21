import {
    Laptop, Wrench, Truck, Armchair, Building2, Cpu, Boxes, Package,
} from 'lucide-vue-next';

/**
 * Shared vocabulary for Asset Management: the enum values the API stores, the
 * label each one shows and the antd tag colour it wears. Every asset page reads
 * from here, so a status can never look one way in the register and another in
 * a report.
 */

export const ASSET_STATUSES = [
    { value: 'in_use', label: 'In use', color: 'success' },
    { value: 'maintenance', label: 'In maintenance', color: 'warning' },
    { value: 'retired', label: 'Retired', color: 'default' },
];

export const MAINTENANCE_TYPES = [
    { value: 'service', label: 'Service', color: 'blue' },
    { value: 'repair', label: 'Repair', color: 'volcano' },
    { value: 'inspection', label: 'Inspection', color: 'cyan' },
    { value: 'calibration', label: 'Calibration', color: 'purple' },
    { value: 'upgrade', label: 'Upgrade', color: 'geekblue' },
    { value: 'other', label: 'Other', color: 'default' },
];

export const MAINTENANCE_STATUSES = [
    { value: 'scheduled', label: 'Scheduled', color: 'processing' },
    { value: 'in_progress', label: 'In progress', color: 'warning' },
    { value: 'completed', label: 'Completed', color: 'success' },
    { value: 'cancelled', label: 'Cancelled', color: 'default' },
];

export const ASSIGNMENT_STATUSES = [
    { value: 'assigned', label: 'Out', color: 'processing' },
    { value: 'returned', label: 'Returned', color: 'success' },
];

/**
 * Depreciation methods. "None" is the default and is deliberately first: most
 * rows in an existing register have no useful life set, and pretending they
 * depreciate would invent numbers nobody entered.
 */
export const DEPRECIATION_METHODS = [
    { value: 'none', label: 'None', hint: 'Book value stays at cost' },
    { value: 'straight_line', label: 'Straight line', hint: 'Equal charge every month of the life' },
    { value: 'declining_balance', label: 'Declining balance', hint: 'Double rate on the remaining value — front-loaded' },
];

/**
 * Icons for the category chips. Categories are user-defined, so these are
 * matched on the name rather than an id — a soft touch that gives "Vehicles"
 * a truck instead of the same grey box as everything else.
 */
const CATEGORY_ICONS = [
    { match: /laptop|computer|pc|it|electronic/i, icon: Laptop },
    { match: /tool|equipment|machine/i, icon: Wrench },
    { match: /vehicle|car|truck|van/i, icon: Truck },
    { match: /furniture|chair|desk|office/i, icon: Armchair },
    { match: /building|property|premise|land/i, icon: Building2 },
    { match: /server|network|hardware/i, icon: Cpu },
    { match: /stock|material|supply/i, icon: Boxes },
];

export function categoryIcon(name) {
    const hit = CATEGORY_ICONS.find(c => c.match.test(name || ''));
    return hit ? hit.icon : Package;
}

/** The full option object for a value, or a safe blank so templates never throw. */
export function optionOf(list, value) {
    return list.find(o => o.value === value) || { value, label: value || '—', color: 'default' };
}

export function labelOf(list, value) {
    return optionOf(list, value).label;
}

/** Colour a countdown: red once it is late, amber when it is close. */
export function dueColor(days) {
    if (days === null || days === undefined) return 'default';
    if (days < 0) return 'error';
    if (days <= 7) return 'warning';
    if (days <= 30) return 'processing';
    return 'success';
}

/** "3 days late" / "in 12 days" / "today" — the countdown as a sentence. */
export function dueLabel(days) {
    if (days === null || days === undefined) return '—';
    if (days < 0) return `${Math.abs(days)} day${Math.abs(days) === 1 ? '' : 's'} late`;
    if (days === 0) return 'Today';
    return `in ${days} day${days === 1 ? '' : 's'}`;
}
