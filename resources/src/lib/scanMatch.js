/**
 * Resolve a scanned/typed barcode to a product, mirroring the legacy create
 * forms' search() exactly:
 *  - weighing forms (sales, transfers) accept a 13-digit numeric scale barcode:
 *    first an exact product-code match, else the first 7 digits are the product
 *    code and digits 7–12 (grams) are the weight in kg.
 *  - otherwise a product whose `code` equals the input, or whose `barcode`
 *    contains it, and only when that yields exactly one product (legacy only
 *    auto-adds a single match; ambiguous input falls back to manual search).
 *
 * Returns { idx, weight } — idx into the products array, weight in kg or null —
 * or null when nothing matched.
 */
export function resolveScan(products, rawCode, { weighing = false } = {}) {
    const code = String(rawCode ?? '').trim();
    if (!code || !Array.isArray(products)) return null;

    // Weighing-scale barcode (13 digits, all numeric).
    if (weighing && code.length === 13 && !Number.isNaN(Number(code))) {
        let idx = products.findIndex(p => p.code === code);
        if (idx !== -1) return { idx, weight: null };

        const productCode = code.substring(0, 7); // first 7 digits → product code
        const weight = parseFloat(code.substring(7, 12)) / 1000; // grams → kg
        idx = products.findIndex(p => p.code === productCode);
        if (idx !== -1) return { idx, weight };

        return null; // an invalid scale code never falls through to text search
    }

    // Regular barcode: exact code or barcode-contains, single match only.
    const matches = [];
    products.forEach((p, i) => {
        if (p.code === code || String(p.barcode ?? '').includes(code)) matches.push(i);
    });
    return matches.length === 1 ? { idx: matches[0], weight: null } : null;
}
