import { t as tEs } from '../../i18n';
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
    { value: 'car', get label() { return tEs('tx_Car_e9989', 'Car'); }, icon: Car },
    { value: 'van', get label() { return tEs('tx_Van_585f9', 'Van'); }, icon: Caravan },
    { value: 'truck', get label() { return tEs('tx_Truck_6f138', 'Truck'); }, icon: Truck },
    { value: 'bus', label: 'Bus', icon: Bus },
    { value: 'motorcycle', get label() { return tEs('tx_Motorcycle_5683d', 'Motorcycle'); }, icon: Bike },
    { value: 'forklift', get label() { return tEs('tx_Forklift_46c0e', 'Forklift'); }, icon: Forklift },
    { value: 'trailer', get label() { return tEs('tx_Trailer_7777d', 'Trailer'); }, icon: Container },
    { value: 'other', get label() { return tEs('tx_Other_6311a', 'Other'); }, icon: Package },
];

/** The glyph for a vehicle's type, falling back to the generic car. */
export function typeIcon(type) {
    return (VEHICLE_TYPES.find(t => t.value === type) || VEHICLE_TYPES[0]).icon;
}

export const VEHICLE_STATUSES = [
    { value: 'active', get label() { return tEs('tx_Active_4d3d7', 'Active'); }, color: 'success' },
    { value: 'maintenance', get label() { return tEs('tx_In_maintenance_575ed', 'In maintenance'); }, color: 'warning' },
    { value: 'inactive', get label() { return tEs('tx_Inactive_3cab0', 'Inactive'); }, color: 'default' },
    { value: 'sold', get label() { return tEs('tx_Sold_d85a8', 'Sold'); }, color: 'error' },
];

export const FUEL_TYPES = [
    { value: 'petrol', get label() { return tEs('tx_Petrol_f132f', 'Petrol'); } },
    { value: 'diesel', get label() { return tEs('tx_Diesel_6fc79', 'Diesel'); } },
    { value: 'electric', get label() { return tEs('tx_Electric_a7471', 'Electric'); } },
    { value: 'hybrid', get label() { return tEs('tx_Hybrid_fb1b6', 'Hybrid'); } },
    { value: 'lpg', get label() { return tEs('tx_LPG_cc5b5', 'LPG'); } },
    { value: 'cng', get label() { return tEs('tx_CNG_6fcea', 'CNG'); } },
];

export const MAINTENANCE_TYPES = [
    { value: 'service', get label() { return tEs('tx_Service_c2ba7', 'Service'); }, color: 'blue' },
    { value: 'repair', get label() { return tEs('tx_Repair_0493d', 'Repair'); }, color: 'volcano' },
    { value: 'tyres', get label() { return tEs('tx_Tyres_46612', 'Tyres'); }, color: 'purple' },
    { value: 'inspection', get label() { return tEs('tx_Inspection_b4faa', 'Inspection'); }, color: 'cyan' },
    { value: 'insurance', get label() { return tEs('tx_Insurance_eaff1', 'Insurance'); }, color: 'geekblue' },
    { value: 'other', get label() { return tEs('tx_Other_6311a', 'Other'); }, color: 'default' },
];

export const MAINTENANCE_STATUSES = [
    { value: 'scheduled', get label() { return tEs('tx_Scheduled_2b7da', 'Scheduled'); }, color: 'processing' },
    { value: 'in_progress', get label() { return tEs('tx_In_progress_b6e0b', 'In progress'); }, color: 'warning' },
    { value: 'completed', get label() { return tEs('tx_Completed_07ca5', 'Completed'); }, color: 'success' },
];

export const ASSIGNMENT_STATUSES = [
    { value: 'active', get label() { return tEs('tx_Out_7c147', 'Out'); }, color: 'processing' },
    { value: 'completed', get label() { return tEs('tx_Returned_fdfac', 'Returned'); }, color: 'success' },
    { value: 'cancelled', get label() { return tEs('tx_Cancelled_a149e', 'Cancelled'); }, color: 'default' },
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
