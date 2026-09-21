/**
 * Sales document vocabularies — backend enum values with the i18n keys legacy
 * renders them with (note the odd casings: 'complete' lowercase, 'partial'
 * lowercase). Colours come from lib/statusColors.js.
 */

export const SALE_STATUSES = [
    { value: 'completed', key: 'complete' },
    { value: 'pending', key: 'Pending' },
    { value: 'ordered', key: 'Ordered' },
];

export const PAYMENT_STATUSES = [
    { value: 'paid', key: 'Paid' },
    { value: 'partial', key: 'partial' },
    { value: 'unpaid', key: 'Unpaid' },
];

export const SHIPPING_STATUSES = [
    { value: 'ordered', key: 'Ordered' },
    { value: 'packed', key: 'Packed' },
    { value: 'shipped', key: 'Shipped' },
    { value: 'delivered', key: 'Delivered' },
    { value: 'cancelled', key: 'Cancelled' },
];

export function statusKey(list, value) {
    return list.find(s => s.value === value)?.key || null;
}

export function shipStatusColor(status) {
    const map = {
        ordered: 'warning',
        packed: 'processing',
        shipped: 'blue',
        delivered: 'success',
        cancelled: 'error',
    };
    return map[status] || 'default';
}
