/**
 * Shared vocabulary for the hospital module: the enum values the API stores,
 * the label each shows and the antd tag colour it wears. Every hospital page
 * reads these, so a status can never look one way in a list and another in a
 * report.
 *
 * Colour choices are clinical, not decorative: anything meaning "needs
 * attention now" is red, "in progress" is amber, "settled" is green.
 */

export const GENDERS = [
    { value: 'male', label: 'Male' },
    { value: 'female', label: 'Female' },
    { value: 'other', label: 'Other' },
];

export const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']
    .map(g => ({ value: g, label: g }));

export const PATIENT_STATUSES = [
    { value: 'active', label: 'Active', color: 'success' },
    { value: 'inactive', label: 'Inactive', color: 'default' },
    { value: 'deceased', label: 'Deceased', color: 'error' },
];

export const APPOINTMENT_TYPES = [
    { value: 'consultation', label: 'Consultation', color: 'blue' },
    { value: 'follow_up', label: 'Follow-up', color: 'cyan' },
    { value: 'procedure', label: 'Procedure', color: 'purple' },
    { value: 'emergency', label: 'Emergency', color: 'red' },
    { value: 'teleconsult', label: 'Teleconsult', color: 'geekblue' },
];

export const APPOINTMENT_STATUSES = [
    { value: 'scheduled', label: 'Scheduled', color: 'default' },
    { value: 'confirmed', label: 'Confirmed', color: 'processing' },
    { value: 'arrived', label: 'Arrived', color: 'warning' },
    { value: 'completed', label: 'Completed', color: 'success' },
    { value: 'cancelled', label: 'Cancelled', color: 'default' },
    { value: 'no_show', label: 'No show', color: 'error' },
];

export const VISIT_TYPES = [
    { value: 'opd', label: 'OPD', color: 'blue' },
    { value: 'emergency', label: 'Emergency', color: 'red' },
    { value: 'follow_up', label: 'Follow-up', color: 'cyan' },
    { value: 'teleconsult', label: 'Teleconsult', color: 'geekblue' },
];

export const VISIT_STATUSES = [
    { value: 'open', label: 'Open', color: 'processing' },
    { value: 'completed', label: 'Completed', color: 'success' },
];

export const WARD_TYPES = [
    { value: 'general', label: 'General' },
    { value: 'private', label: 'Private' },
    { value: 'semi_private', label: 'Semi-private' },
    { value: 'icu', label: 'ICU' },
    { value: 'nicu', label: 'NICU' },
    { value: 'maternity', label: 'Maternity' },
    { value: 'isolation', label: 'Isolation' },
];

export const BED_STATUSES = [
    { value: 'available', label: 'Available', color: 'success' },
    { value: 'occupied', label: 'Occupied', color: 'error' },
    { value: 'reserved', label: 'Reserved', color: 'warning' },
    { value: 'maintenance', label: 'Maintenance', color: 'default' },
];

export const ADMISSION_STATUSES = [
    { value: 'admitted', label: 'Admitted', color: 'processing' },
    { value: 'discharged', label: 'Discharged', color: 'success' },
    { value: 'transferred', label: 'Transferred', color: 'warning' },
    { value: 'deceased', label: 'Deceased', color: 'error' },
];

export const LAB_PRIORITIES = [
    { value: 'routine', label: 'Routine', color: 'default' },
    { value: 'urgent', label: 'Urgent', color: 'warning' },
    { value: 'stat', label: 'STAT', color: 'error' },
];

export const LAB_STATUSES = [
    { value: 'ordered', label: 'Ordered', color: 'default' },
    { value: 'sample_collected', label: 'Sample collected', color: 'processing' },
    { value: 'in_progress', label: 'In progress', color: 'warning' },
    { value: 'completed', label: 'Completed', color: 'success' },
    { value: 'cancelled', label: 'Cancelled', color: 'default' },
];

export const RESULT_FLAGS = [
    { value: 'normal', label: 'Normal', color: 'success' },
    { value: 'low', label: 'Low', color: 'warning' },
    { value: 'high', label: 'High', color: 'warning' },
    { value: 'critical', label: 'Critical', color: 'error' },
];

export const INVOICE_STATUSES = [
    { value: 'draft', label: 'Draft', color: 'default' },
    { value: 'unpaid', label: 'Unpaid', color: 'error' },
    { value: 'partial', label: 'Partly paid', color: 'warning' },
    { value: 'paid', label: 'Paid', color: 'success' },
    { value: 'cancelled', label: 'Cancelled', color: 'default' },
];

export const INVOICE_ITEM_TYPES = [
    { value: 'consultation', label: 'Consultation' },
    { value: 'procedure', label: 'Procedure' },
    { value: 'medicine', label: 'Medicine' },
    { value: 'lab', label: 'Lab test' },
    { value: 'bed', label: 'Bed charge' },
    { value: 'other', label: 'Other' },
];

export const PAYMENT_METHODS = [
    { value: 'cash', label: 'Cash' },
    { value: 'card', label: 'Card' },
    { value: 'bank_transfer', label: 'Bank transfer' },
    { value: 'insurance', label: 'Insurance' },
    { value: 'cheque', label: 'Cheque' },
    { value: 'other', label: 'Other' },
];

export const WEEK_DAYS = [
    { value: 'mon', label: 'Mon' },
    { value: 'tue', label: 'Tue' },
    { value: 'wed', label: 'Wed' },
    { value: 'thu', label: 'Thu' },
    { value: 'fri', label: 'Fri' },
    { value: 'sat', label: 'Sat' },
    { value: 'sun', label: 'Sun' },
];

/** Look one up, falling back to the raw value so nothing renders blank. */
export function optionOf(list, value) {
    return list.find(o => o.value === value) || { value, label: value || '—', color: 'default' };
}

export function labelOf(list, value) {
    return optionOf(list, value).label;
}

/**
 * Vital-sign interpretation for adults, used to tint the readings on screen.
 * Deliberately coarse: this flags what a clinician should LOOK at, it does not
 * diagnose, and paediatric ranges differ enough that under-16s get no flag.
 */
export function vitalTone(key, value, age = null) {
    if (value === null || value === undefined || value === '') return null;
    const n = Number(value);
    if (Number.isNaN(n)) return null;
    if (age !== null && age !== undefined && age < 16) return null;

    const ranges = {
        temperature: [36.1, 37.8],
        pulse: [60, 100],
        bp_systolic: [90, 140],
        bp_diastolic: [60, 90],
        respiratory_rate: [12, 20],
        spo2: [95, 100],
    };

    const range = ranges[key];
    if (!range) return null;
    if (n < range[0]) return 'low';
    if (n > range[1]) return 'high';
    return 'normal';
}

/** Standard adult BMI bands. */
export function bmiBand(bmi) {
    if (!bmi) return null;
    if (bmi < 18.5) return { label: 'Underweight', color: 'warning' };
    if (bmi < 25) return { label: 'Normal', color: 'success' };
    if (bmi < 30) return { label: 'Overweight', color: 'warning' };
    return { label: 'Obese', color: 'error' };
}
