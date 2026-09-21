/**
 * Price formatting — ported 1:1 from the legacy app's resources/src/utils/priceFormat.js,
 * with the Vuex-store branches removed (the auth pinia store supplies the settings).
 * Presentational only: never used for calculations or stored values.
 *
 * Formats:
 *   comma_dot   => 1,234.56
 *   dot_comma   => 1.234,56
 *   space_comma => 1 234,56
 */

export const PRICE_FORMATS = {
    comma_dot: { thousands: ',', decimal: '.' },
    dot_comma: { thousands: '.', decimal: ',' },
    space_comma: { thousands: ' ', decimal: ',' },
};

export function normalizePriceFormatKey(input) {
    if (!input) return null;
    const raw = String(input).trim();
    if (PRICE_FORMATS[raw]) return raw;

    // Safety net: some settings rows store the human label instead of the key.
    const labelMap = {
        '1,234.56 (thousand , decimal .)': 'comma_dot',
        '1.234,56 (thousand . decimal ,)': 'dot_comma',
        '1 234,56 (thousand space, decimal ,)': 'space_comma',
    };
    return labelMap[raw] || null;
}

export function formatPriceDisplay(value, decimals = 2, formatKey = null) {
    const d = Number.isInteger(decimals) ? decimals : 0;
    const n = Number(value);
    const safe = Number.isFinite(n) ? n : 0;
    const key = normalizePriceFormatKey(formatKey);

    // Legacy default (en-US style) when no format is configured.
    if (!key) {
        try {
            return safe.toLocaleString('en-US', {
                minimumFractionDigits: d,
                maximumFractionDigits: d,
            });
        } catch (e) {
            const fixed = safe.toFixed(d);
            const [intPart, fracPart = ''] = fixed.split('.');
            const withCommas = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            return d > 0 ? `${withCommas}.${fracPart}` : withCommas;
        }
    }

    const cfg = PRICE_FORMATS[key];
    const fixed = safe.toFixed(d);
    let [intPart, fracPart = ''] = fixed.split('.');
    intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, cfg.thousands);

    if (d <= 0) return intPart;
    if (fracPart.length < d) fracPart = fracPart.padEnd(d, '0');
    return `${intPart}${cfg.decimal}${fracPart}`;
}

/**
 * Round a monetary amount to a real Number (not a display string). Use for
 * values that feed calculations or input bounds — e.g. an a-input-number
 * :max — where a raw float sum like 121.99999999999994 would leak into the UI.
 */
export function roundAmount(value, decimals = 2) {
    const n = Number(value);
    if (!Number.isFinite(n)) return 0;
    const d = Number.isInteger(decimals) && decimals >= 0 ? decimals : 2;
    // Round via the fixed-decimal string: immune to the 1.005 * 100 = 100.49999 class of errors.
    return Number(n.toFixed(d));
}

/** Monetary precision: 2 or 3, mirroring the "Enable 3 Decimal Pricing" setting. */
export function resolvePriceDecimals(userValue) {
    const n = parseInt(userValue, 10);
    if (n === 3) return 3;
    if (n === 2) return 2;
    try {
        if (parseInt(window.localStorage.getItem('app_price_decimals'), 10) === 3) return 3;
    } catch (e) { /* ignore */ }
    return 2;
}
