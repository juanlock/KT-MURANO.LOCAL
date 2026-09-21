/**
 * Shared formatters for WooCommerce sync log rows (legacy StatusOverviewTab/
 * LogsTab helpers, verbatim semantics). Log rows: {created_at, action
 * "products.push"|"stock.push"|"orders.pull"..., level info|warning|error,
 * message}.
 */
import dayjs from 'dayjs';

export function formatLogDate(val) {
    return val ? dayjs(val).format('YYYY-MM-DD HH:mm') : '';
}

export function actionKind(action) {
    return String(action || '').split('.')[0];
}

export function formatAction(action, t) {
    const key = actionKind(action);
    if (key === 'products') return t('Product');
    if (key === 'orders') return t('Order');
    if (key === 'stock') return t('Stock');
    return key;
}

export function formatDirection(action, t) {
    // Orders flow WooCommerce → POS; everything else pushes POS → WooCommerce.
    return actionKind(action) === 'orders' ? t('WooCommerce_to_POS') : t('POS_to_WooCommerce');
}

export function formatStatus(level, t) {
    if (level === 'error') return t('Failed');
    if (level === 'warning') return t('Warning');
    return t('Success');
}

export function levelColor(level) {
    if (level === 'error') return 'error';
    if (level === 'warning') return 'warning';
    return 'success';
}
