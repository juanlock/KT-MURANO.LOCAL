import { Car, Truck, Bus, Bike, Forklift, Caravan, Container, Package } from 'lucide-vue-next';

/**
 * Shared vocabulary for the fleet module: the enum values the API stores, the
 * label each one shows and the antd tag colour it wears. Every fleet page reads
 * these, so a status can never look one way in the list and another in a report.
 *
 * Type icons come from lucide (the sidebar's icon set) rather than antd, which
 * ships a car and nothing else — a fleet where a bus, a forklift and a trailer
 * all render as the same car glyph tells the user nothing.
 */

export const VEHICLE_TYPES = [
    { value: 'car', label: 'Car', icon: Car },
    { value: 'van', label: 'Van', icon: Caravan },
    { value: 'truck', label: 'Truck', icon: Truck },
    { value: 'bus', label: 'Bus', icon: Bus },
    { value: 'motorcycle', label: 'Motorcycle', icon: Bike },
    { value: 'forklift', label: 'Forklift', icon: Forklift },
    { value: 'trailer', label: 'Trailer', icon: Container },
    { value: 'other', label: 'Other', icon: Package },
];

/** The glyph for a vehicle's type, falling back to the generic car. */
export function typeIcon(type) {
    return (VEHICLE_TYPES.find(t => t.value === type) || VEHICLE_TYPES[0]).icon;
}

export const VEHICLE_STATUSES = [
    { value: 'active', label: 'Active', color: 'success' },
    { value: 'maintenance', label: 'In maintenance', color: 'warning' },
    { value: 'inactive', label: 'Inactive', color: 'default' },
    { value: 'sold', label: 'Sold', color: 'error' },
];

export const FUEL_TYPES = [
    { value: 'petrol', label: 'Petrol' },
    { value: 'diesel', label: 'Diesel' },
    { value: 'electric', label: 'Electric' },
    { value: 'hybrid', label: 'Hybrid' },
    { value: 'lpg', label: 'LPG' },
    { value: 'cng', label: 'CNG' },
];

export const MAINTENANCE_TYPES = [
    { value: 'service', label: 'Service', color: 'blue' },
    { value: 'repair', label: 'Repair', color: 'volcano' },
    { value: 'tyres', label: 'Tyres', color: 'purple' },
    { value: 'inspection', label: 'Inspection', color: 'cyan' },
    { value: 'insurance', label: 'Insurance', color: 'geekblue' },
    { value: 'other', label: 'Other', color: 'default' },
];

export const MAINTENANCE_STATUSES = [
    { value: 'scheduled', label: 'Scheduled', color: 'processing' },
    { value: 'in_progress', label: 'In progress', color: 'warning' },
    { value: 'completed', label: 'Completed', color: 'success' },
];

export const ASSIGNMENT_STATUSES = [
    { value: 'active', label: 'Out', color: 'processing' },
    { value: 'completed', label: 'Returned', color: 'success' },
    { value: 'cancelled', label: 'Cancelled', color: 'default' },
];

/** Look one up, falling back to the raw value so nothing renders blank. */
export function optionOf(list, value) {
    return list.find(o => o.value === value) || { value, label: value || '—', color: 'default' };
}

export function labelOf(list, value) {
    return optionOf(list, value).label;
}

/**
 * How urgent a renewal is. `days` is negative once the date has passed.
 * Returns null when there is nothing to warn about, so callers can `v-if` it.
 */
export function expiryTone(days, soonWithin = 30) {
    if (days === null || days === undefined) return null;
    if (days < 0) return { color: 'error', text: `Overdue by ${Math.abs(days)}d`, level: 'overdue' };
    if (days === 0) return { color: 'error', text: 'Due today', level: 'overdue' };
    if (days <= soonWithin) return { color: 'warning', text: `${days}d left`, level: 'soon' };
    return null;
}
