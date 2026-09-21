/**
 * Contract vocabularies — values and English labels hardcoded in legacy too.
 * Party types: customer | employee.
 */
export const CONTRACT_TYPES = [
    { value: 'service', label: 'Service' },
    { value: 'lease', label: 'Lease' },
    { value: 'sales', label: 'Sales' },
    { value: 'nda', label: 'NDA' },
    { value: 'employment', label: 'Employment' },
    { value: 'other', label: 'Other' },
];

export const CONTRACT_STATUSES = [
    { value: 'draft', label: 'Draft' },
    { value: 'active', label: 'Active' },
    { value: 'expired', label: 'Expired' },
    { value: 'cancelled', label: 'Cancelled' },
];

export const contractTypeLabel = v => CONTRACT_TYPES.find(x => x.value === v)?.label || v;
export const contractStatusLabel = v => CONTRACT_STATUSES.find(x => x.value === v)?.label || v;

export function contractStatusColor(v) {
    switch (v) {
        case 'active': return 'success';
        case 'draft': return 'default';
        case 'expired': return 'warning';
        case 'cancelled': return 'error';
        default: return 'default';
    }
}
