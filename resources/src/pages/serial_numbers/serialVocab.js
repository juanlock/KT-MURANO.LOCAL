/** Serial-number status vocabulary — backend enums, i18n keys `Status_{value}`. */

export const SERIAL_STATUSES = [
    'available', 'sold', 'returned_customer', 'returned_supplier', 'damaged', 'reserved',
];

export function serialStatusColor(status) {
    const map = {
        available: 'success',
        sold: 'processing',
        returned_customer: 'warning',
        returned_supplier: 'warning',
        damaged: 'error',
        reserved: 'default',
    };
    return map[status] || 'default';
}
