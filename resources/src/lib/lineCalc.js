/**
 * Line + order math for the multi-line documents (sales, purchases,
 * quotations…), copied formula-for-formula from the legacy forms — parity
 * beats elegance here, totals must match to the cent.
 *
 * Line vocabulary (legacy field names, kept verbatim because they go straight
 * into the API payload):
 *   Unit_price      price per unit as edited
 *   discount        per-line discount value
 *   discount_Method '1' percent of Unit_price | '2' fixed
 *   DiscountNet     computed discount amount per unit
 *   tax_percent     per-line tax %
 *   tax_method      '1' exclusive | '2' inclusive
 *   taxe            computed tax amount per unit
 *   Net_price       computed net unit price
 *   subtotal        quantity * Net_price + taxe * quantity
 */

/** Recompute DiscountNet / taxe / Net_price / subtotal in place. */
export function recomputeLine(line) {
    const price = Number(line.Unit_price) || 0;
    const discount = Number(line.discount) || 0;
    const taxPercent = Number(line.tax_percent) || 0;
    const qty = Number(line.quantity) || 0;

    line.DiscountNet = String(line.discount_Method) === '2'
        ? discount
        : parseFloat(((price * discount) / 100).toFixed(6));

    const base = price - line.DiscountNet;
    if (String(line.tax_method) === '1') {
        // Exclusive
        line.Net_price = parseFloat(base);
        line.taxe = parseFloat(((taxPercent * base) / 100).toFixed(6));
    } else {
        // Inclusive — legacy's exact formula, deliberately not the algebraic one.
        line.taxe = parseFloat((base * (taxPercent / 100)).toFixed(6));
        line.Net_price = parseFloat(price - line.taxe - line.DiscountNet);
    }

    line.subtotal = parseFloat((qty * line.Net_price + line.taxe * qty).toFixed(2));
    return line;
}

/**
 * Purchase variant: identical math on the cost fields (Unit_cost/Net_cost;
 * `taxe` starts as tax_cost from show_product_data).
 */
export function recomputeCostLine(line) {
    const cost = Number(line.Unit_cost) || 0;
    const discount = Number(line.discount) || 0;
    const taxPercent = Number(line.tax_percent) || 0;
    const qty = Number(line.quantity) || 0;

    line.DiscountNet = String(line.discount_Method) === '2'
        ? discount
        : parseFloat(((cost * discount) / 100).toFixed(6));

    const base = cost - line.DiscountNet;
    if (String(line.tax_method) === '1') {
        line.Net_cost = parseFloat(base);
        line.taxe = parseFloat(((taxPercent * base) / 100).toFixed(6));
    } else {
        line.taxe = parseFloat((base * (taxPercent / 100)).toFixed(6));
        line.Net_cost = parseFloat(cost - line.taxe - line.DiscountNet);
    }

    line.subtotal = parseFloat((qty * line.Net_cost + line.taxe * qty).toFixed(2));
    return line;
}

/**
 * Purchase/quotation order totals — legacy's simpler model: a plain fixed
 * discount subtracted UNCAPPED (matches Calcul_Total: total - discount).
 */
export function computeSimpleTotals(lines, { discount = 0, taxRate = 0, shipping = 0 } = {}, recompute = recomputeLine) {
    let total = 0;
    for (const line of lines) {
        recompute(line);
        total = parseFloat((total + line.subtotal).toFixed(2));
    }
    const totalAfterDiscount = parseFloat((total - (Number(discount) || 0)).toFixed(6));
    const TaxNet = parseFloat(((totalAfterDiscount * (Number(taxRate) || 0)) / 100).toFixed(6));
    const GrandTotal = parseFloat((totalAfterDiscount + TaxNet + (Number(shipping) || 0)).toFixed(2));
    return { total, discountAmount: Number(discount) || 0, TaxNet, GrandTotal };
}

/**
 * Order totals from the lines + order-level tax/discount/shipping.
 * Returns { total, discountAmount, TaxNet, GrandTotal }.
 */
export function computeTotals(lines, {
    discount = 0,
    discountMethod = '2',
    taxRate = 0,
    shipping = 0,
    discountFromPoints = 0,
} = {}) {
    let total = 0;
    for (const line of lines) {
        recomputeLine(line);
        total = parseFloat((total + line.subtotal).toFixed(2));
    }

    const discountValue = Number(discount) || 0;
    let discountAmount;
    if (String(discountMethod) === '1') {
        const percentAmount = parseFloat((total * (discountValue / 100)).toFixed(2));
        const remaining = Math.max(total - percentAmount, 0);
        discountAmount = percentAmount + parseFloat(Math.min(Number(discountFromPoints) || 0, remaining).toFixed(2));
    } else {
        const manual = parseFloat(Math.min(discountValue, total).toFixed(2));
        const remaining = Math.max(total - manual, 0);
        discountAmount = manual + parseFloat(Math.min(Number(discountFromPoints) || 0, remaining).toFixed(2));
    }

    const totalAfterDiscount = parseFloat((total - discountAmount).toFixed(2));
    const TaxNet = parseFloat(((totalAfterDiscount * (Number(taxRate) || 0)) / 100).toFixed(6));
    const GrandTotal = parseFloat((totalAfterDiscount + TaxNet + (Number(shipping) || 0)).toFixed(2));

    return { total, discountAmount, TaxNet, GrandTotal };
}
