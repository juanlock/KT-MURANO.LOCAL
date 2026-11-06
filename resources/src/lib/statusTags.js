import { t as tEs } from '../i18n/index.js';
import { t as tf } from '../i18n';

/**
 * Status → {color, label} maps copied from the legacy report templates, badge
 * for badge. Every drill-down report repeated these inline; the mappings differ
 * per document type in ways that look like accidents but are the shipped
 * behaviour, so they are kept distinct rather than merged:
 *
 *  - a SALE that is neither completed nor pending reads "Ordered"; a customer
 *    RETURN that is not received reads "Pending" (no Ordered state).
 *  - a supplier return uses completed/"complete"; a customer return uses
 *    received/"Received".
 *  - a TRANSFER that is neither completed nor sent is danger-red, while a
 *    pending sale is blue.
 *
 * Legacy badge classes map to Ant tag colours as:
 *   success → success · info → processing · warning → warning
 *   primary → blue · danger → error · secondary → default
 */

const tag = (color, key) => ({ color, label: tf(key, key) });

export function saleStatusTag(statut) {
    if (statut === 'completed') return tag('success', 'complete');
    if (statut === 'pending') return tag('processing', tEs('EnAttendant', 'Pending'));
    return tag('warning', tEs('Ordered', 'Ordered'));
}

export function purchaseStatusTag(statut) {
    if (statut === 'received') return tag('success', tEs('Received', 'Received'));
    if (statut === 'pending') return tag('processing', tEs('EnAttendant', 'Pending'));
    return tag('warning', tEs('Ordered', 'Ordered'));
}

/** Customer-side returns: received or pending, nothing else. */
export function saleReturnStatusTag(statut) {
    return statut === 'received' ? tag('success', tEs('Received', 'Received')) : tag('processing', tEs('EnAttendant', 'Pending'));
}

/** Supplier-side returns: completed or pending. */
export function purchaseReturnStatusTag(statut) {
    return statut === 'completed' ? tag('success', 'complete') : tag('processing', tEs('EnAttendant', 'Pending'));
}

export function quotationStatusTag(statut) {
    return statut === 'sent' ? tag('success', tEs('Sent', 'Sent')) : tag('processing', tEs('EnAttendant', 'Pending'));
}

export function transferStatusTag(statut) {
    if (statut === 'completed') return tag('success', 'complete');
    if (statut === 'sent') return tag('warning', tEs('Sent', 'Sent'));
    return tag('error', tEs('EnAttendant', 'Pending'));
}

export function paymentStatusTag(status) {
    if (status === 'paid') return tag('success', tEs('Paid', 'Paid'));
    if (status === 'partial') return tag('blue', 'partial');
    return tag('warning', tEs('Unpaid', 'Unpaid'));
}

const SHIPPING = {
    ordered: ['warning', 'Ordered'],
    packed: ['processing', 'Packed'],
    shipped: ['default', 'Shipped'],
    delivered: ['success', 'Delivered'],
    cancelled: ['error', 'Cancelled'],
};

/** Legacy rendered nothing for an unknown shipping status — so do we. */
export function shippingStatusTag(status) {
    const hit = SHIPPING[status];
    return hit ? tag(hit[0], hit[1]) : null;
}

/** Column keys that render as a tag rather than plain text. */
export const TAG_KEYS = ['statut', 'payment_status', 'shipping_status'];

const DOC_STATUS = {
    sale: saleStatusTag,
    purchase: purchaseStatusTag,
    sale_return: saleReturnStatusTag,
    purchase_return: purchaseReturnStatusTag,
    quotation: quotationStatusTag,
    transfer: transferStatusTag,
};

/**
 * Tag for a status column, dispatched on the document type because `statut`
 * means different things per document (see the note above).
 * Returns null when nothing should render.
 */
export function documentTag(doc, columnKey, record) {
    if (columnKey === 'payment_status') return paymentStatusTag(record.payment_status);
    if (columnKey === 'shipping_status') return shippingStatusTag(record.shipping_status);
    if (columnKey === 'statut') {
        const fn = DOC_STATUS[doc];
        return fn ? fn(record.statut) : null;
    }
    return null;
}
