/**
 * Sales document vocabularies — backend enum values with the i18n keys legacy
 * renders them with (note the odd casings: 'complete' lowercase, 'partial'
 * lowercase). Colours come from lib/statusColors.js.
 */

export const SALE_STATUSES = [
    { value: 'completed', key: 'complete' },
    { value: 'pending', key: 'Pending' },
    { value: 'ordered', key: 'Ordered' },
    // Reachable only via the "Cancel" row action on a Pending sale
    // (SalesController@cancel) -- never set at creation.
    { value: 'cancelled', key: 'Cancelled' },
];

// paid/partial/unpaid are computed automatically from paid_amount vs
// GrandTotal (see SalesController/PurchasesController) -- never set directly.
// pending/Cancelled are legacy values: no current code path writes them, but
// older/imported records still carry them, so they stay filterable here.
export const PAYMENT_STATUSES = [
    { value: 'paid', key: 'Paid' },
    { value: 'partial', key: 'partial' },
    { value: 'unpaid', key: 'Unpaid' },
    // Own key (not the shared 'Pending' the document Status column uses) --
    // labeled distinctly here so relabeling one never touches the other.
    { value: 'pending', key: 'PaymentPending' },
    { value: 'Cancelled', key: 'Cancelled' },
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
