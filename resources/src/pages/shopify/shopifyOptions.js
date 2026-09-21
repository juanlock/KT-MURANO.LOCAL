import {
    Package, Boxes, Users, ReceiptText, LayoutGrid, Truck,
} from 'lucide-vue-next';

/**
 * Shared vocabulary for the Shopify module: the values the API stores, the
 * label each shows and the antd colour it wears. Every Shopify page reads from
 * here, so a run cannot look "failed" on one screen and "error" on another.
 */

export const STORE_STATUSES = [
    { value: 'connected', label: 'Connected', color: 'success' },
    { value: 'error', label: 'Error', color: 'error' },
    { value: 'disconnected', label: 'Not connected', color: 'default' },
];

/**
 * The six things this module moves. `directions` is the honest list of what is
 * possible, not what is convenient: orders are pull-only because Shopify is
 * where a customer places one — pushing an ERP sale back would invent an order
 * that never happened.
 */
export const ENTITIES = [
    {
        value: 'products',
        label: 'Products',
        icon: Package,
        directions: ['push', 'pull'],
        hint: 'Catalogue, prices and variants.',
    },
    {
        value: 'inventory',
        label: 'Inventory',
        icon: Boxes,
        directions: ['push', 'pull'],
        hint: 'Stock on hand for the mapped warehouse and location.',
    },
    {
        value: 'customers',
        label: 'Customers',
        icon: Users,
        directions: ['push', 'pull'],
        hint: 'Matched on email — customers without one are skipped.',
    },
    {
        value: 'orders',
        label: 'Orders',
        icon: ReceiptText,
        directions: ['pull'],
        hint: 'Imported as sales. Stock moves only for fulfilled orders.',
    },
    {
        value: 'collections',
        label: 'Collections',
        icon: LayoutGrid,
        directions: ['push', 'pull'],
        hint: 'Mapped to ERP categories.',
    },
    {
        value: 'fulfillments',
        label: 'Fulfillments',
        icon: Truck,
        directions: ['push', 'pull'],
        hint: 'Push marks shipped orders fulfilled; pull refreshes their status.',
    },
];

export const RUN_STATUSES = [
    { value: 'pending', label: 'Pending', color: 'default' },
    { value: 'running', label: 'Running', color: 'processing' },
    { value: 'completed', label: 'Completed', color: 'success' },
    { value: 'failed', label: 'Failed', color: 'error' },
    { value: 'cancelled', label: 'Cancelled', color: 'warning' },
    // Not a stored status: a run whose worker stopped sending heartbeats.
    { value: 'stale', label: 'Stalled', color: 'error' },
];

export const DIRECTIONS = [
    { value: 'push', label: 'ERP → Shopify', short: 'Push', color: 'blue' },
    { value: 'pull', label: 'Shopify → ERP', short: 'Pull', color: 'purple' },
];

export const LOG_LEVELS = [
    { value: 'info', label: 'Info', color: 'default' },
    { value: 'warning', label: 'Warning', color: 'warning' },
    { value: 'error', label: 'Error', color: 'error' },
];

export const LINK_TYPES = [
    { value: 'product', label: 'Product' },
    { value: 'variant', label: 'Variant' },
    { value: 'inventory_item', label: 'Inventory item' },
    { value: 'customer', label: 'Customer' },
    { value: 'order', label: 'Order' },
    { value: 'collection', label: 'Collection' },
];

export const PRICE_FIELDS = [
    { value: 'price', label: 'Retail price' },
    { value: 'wholesale_price', label: 'Wholesale price' },
    { value: 'min_price', label: 'Minimum price' },
];

export const WEBHOOK_STATUSES = [
    { value: 'pending', label: 'Pending', color: 'processing' },
    { value: 'processed', label: 'Processed', color: 'success' },
    { value: 'failed', label: 'Failed', color: 'error' },
    { value: 'ignored', label: 'Ignored', color: 'default' },
];

/**
 * Topics worth subscribing to, shown on the store's webhook tab so the values
 * can be copied into Shopify rather than remembered.
 */
export const WEBHOOK_TOPICS = [
    'orders/create',
    'orders/updated',
    'orders/cancelled',
    'orders/fulfilled',
    'products/create',
    'products/update',
    'customers/create',
    'customers/update',
    'inventory_levels/update',
];

export function optionOf(list, value) {
    return list.find(o => o.value === value) || { value, label: value || '—', color: 'default' };
}

export function labelOf(list, value) {
    return optionOf(list, value).label;
}

export function entityOf(value) {
    return ENTITIES.find(e => e.value === value) || { value, label: value, icon: Package, directions: [] };
}

/** Whether an entity can travel in a given direction at all. */
export function supports(entity, direction) {
    return entityOf(entity).directions.includes(direction);
}

/** A run is worth polling only while it might still change. */
export function isLive(status) {
    return status === 'running' || status === 'pending';
}
