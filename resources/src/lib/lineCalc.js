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
 *
 * Money is rounded to the company's price decimals (Settings -> Defaults ->
 * General: 2 or 3), not a fixed 2 -- and the order tax is rounded too, so
 * subtotal - discount + tax + shipping = total exactly as the invoice shows it
 * (it used to keep the tax unrounded while the total was rounded to 2: in
 * 3-decimal mode 390.000 + 25.838 showed a total of 415.840).
 */
import { resolvePriceDecimals } from './priceFormat';

/** The company's money precision (2 or 3). */
function moneyDecimals() {
    let fromUser;
    try {
        // eslint-disable-next-line no-undef
        fromUser = window.__appPriceDecimals;
    } catch (e) { /* no window */ }
    return resolvePriceDecimals(fromUser);
}
/**
 * Round half-up to d decimals via the exponent string: 25.8375 -> 25.838
 * (toFixed gives 25.837, since 25.8375 is 25.83749999... in binary).
 */
const r = (v, d) => {
    const n = Number(v) || 0;
    const sign = n < 0 ? -1 : 1;
    return sign * Number(`${Math.round(Number(`${Math.abs(n)}e${d}`))}e-${d}`);
};

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

    line.subtotal = r(qty * line.Net_price + line.taxe * qty, moneyDecimals());
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

    line.subtotal = r(qty * line.Net_cost + line.taxe * qty, moneyDecimals());
    return line;
}

/**
 * Purchase/quotation order totals — legacy's simpler model: a plain fixed
 * discount subtracted UNCAPPED (matches Calcul_Total: total - discount).
 */
/**
 * Order Tax: '1' percent of the post-discount total (default, matches every
 * sale that predates the toggle) | '2' fixed amount, taken as-is.
 */
function computeTaxAmount(base, taxRate, taxMethod = '1') {
    const rate = Number(taxRate) || 0;
    const d = moneyDecimals();
    // Rounded to the money precision, like the total it adds into.
    return String(taxMethod) === '2'
        ? r(Math.max(rate, 0), d)
        : r((base * rate) / 100, d);
}

export function computeSimpleTotals(lines, { discount = 0, taxRate = 0, taxMethod = '1', shipping = 0 } = {}, recompute = recomputeLine) {
    const d = moneyDecimals();
    let total = 0;
    for (const line of lines) {
        recompute(line);
        total = r(total + line.subtotal, d);
    }
    const totalAfterDiscount = r(total - (Number(discount) || 0), d);
    const TaxNet = computeTaxAmount(totalAfterDiscount, taxRate, taxMethod);
    const GrandTotal = r(totalAfterDiscount + TaxNet + (Number(shipping) || 0), d);
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
    taxMethod = '1',
    shipping = 0,
    discountFromPoints = 0,
} = {}) {
    const d = moneyDecimals();
    let total = 0;
    for (const line of lines) {
        recomputeLine(line);
        total = r(total + line.subtotal, d);
    }

    const discountValue = Number(discount) || 0;
    let discountAmount;
    if (String(discountMethod) === '1') {
        const percentAmount = r(total * (discountValue / 100), d);
        const remaining = Math.max(total - percentAmount, 0);
        discountAmount = r(percentAmount + Math.min(Number(discountFromPoints) || 0, remaining), d);
    } else {
        const manual = r(Math.min(discountValue, total), d);
        const remaining = Math.max(total - manual, 0);
        discountAmount = r(manual + Math.min(Number(discountFromPoints) || 0, remaining), d);
    }

    const totalAfterDiscount = r(total - discountAmount, d);
    const TaxNet = computeTaxAmount(totalAfterDiscount, taxRate, taxMethod);
    const GrandTotal = r(totalAfterDiscount + TaxNet + (Number(shipping) || 0), d);

    return { total, discountAmount, TaxNet, GrandTotal };
}
