/**
 * Batch-allocation validation for the document forms, copied rule-for-rule
 * from legacy create_sale.vue's hasBatchValidationErrors /
 * firstBatchErrorMessage. A "select" allocation row is
 * {product_batch_id, batch_no, expiry_date, qty_available, qty}.
 */

export function batchTotalQty(line) {
    return (Array.isArray(line.batches) ? line.batches : [])
        .reduce((sum, b) => sum + (Number(b.qty) || 0), 0);
}

function hasDuplicates(line) {
    const seen = new Set();
    for (const b of line.batches) {
        if (!b.product_batch_id) continue;
        if (seen.has(b.product_batch_id)) return true;
        seen.add(b.product_batch_id);
    }
    return false;
}

function qtyMismatch(line) {
    return Math.abs(batchTotalQty(line) - (Number(line.quantity) || 0)) > 0.0001;
}

/** Sale/quotation-style validation (allocating EXISTING batches). */
export function hasBatchSelectErrors(lines) {
    for (const d of lines) {
        if (!d || !d.is_batch_tracked) continue;
        const batches = Array.isArray(d.batches) ? d.batches : [];
        if (batches.length === 0) return true;
        for (const b of batches) {
            if (!b.product_batch_id) return true;
            const q = Number(b.qty);
            if (!(q > 0)) return true;
            if (q > (Number(b.qty_available) || 0)) return true;
        }
        if (hasDuplicates(d)) return true;
        if (qtyMismatch(d)) return true;
    }
    return false;
}

/** First failing line's message, with legacy's exact keys. `t` = i18n. */
export function firstBatchSelectError(lines, t) {
    for (const d of lines) {
        if (!d || !d.is_batch_tracked) continue;
        const batches = Array.isArray(d.batches) ? d.batches : [];
        const label = d.name || d.code || '';
        if (batches.length === 0) return `${t('Select_Batch_Required_For')} ${label}`;
        for (const b of batches) {
            if (!b.product_batch_id) return `${t('Select_Batch_Required_For')} ${label}`;
            const q = Number(b.qty);
            if (!(q > 0)) return `${t('Batch_Qty_Required_For')} ${label}`;
            if (q > (Number(b.qty_available) || 0)) return `${t('Batch_Qty_Exceeds_Available')} ${label}`;
        }
        if (hasDuplicates(d)) return `${t('Duplicate_Batch_Selected')} ${label}`;
        if (qtyMismatch(d)) return `${t('Total_batch_qty_mismatch')} — ${label}`;
    }
    return '';
}

/** Purchase-style validation (ENTERING new batches: only presence + total). */
export function hasBatchEntryErrors(lines) {
    return lines.some(d => {
        if (!d || !d.is_batch_tracked) return false;
        if (!Array.isArray(d.batches) || d.batches.length === 0) return true;
        return qtyMismatch(d);
    });
}
