/**
 * Barcode symbology constraints shared by the product form (validation +
 * code generation) and label printing. JsBarcode silently renders nothing
 * when a value doesn't fit the selected symbology, so codes must be checked
 * / generated to match before they reach the printer.
 *
 * GTIN formats (EAN13/EAN8/UPC) are stored WITH their check digit so the
 * value saved in `products.code` is exactly what a scanner returns.
 */

// GTIN mod-10 checksum: weights alternate 1,3 starting from the rightmost
// digit (the check digit). Valid full codes sum to a multiple of 10.
function gtinChecksumValid(digits) {
  let sum = 0;
  for (let i = 0; i < digits.length; i++) {
    const d = digits.charCodeAt(digits.length - 1 - i) - 48;
    sum += i % 2 === 1 ? d * 3 : d;
  }
  return sum % 10 === 0;
}

// Check digit for a GTIN body (code without its check digit).
function gtinCheckDigit(body) {
  let sum = 0;
  for (let i = 0; i < body.length; i++) {
    const d = body.charCodeAt(body.length - 1 - i) - 48;
    sum += i % 2 === 0 ? d * 3 : d;
  }
  return String((10 - (sum % 10)) % 10);
}

function randomDigits(count, nonZeroFirst) {
  let out = nonZeroFirst ? String(1 + Math.floor(Math.random() * 9)) : '';
  while (out.length < count) out += String(Math.floor(Math.random() * 10));
  return out;
}

// GTIN body lengths (without check digit) per symbology.
const GTIN_BODY_LENGTH = { EAN13: 12, EAN8: 7, UPC: 11 };

const CODE39_RE = /^[0-9A-Z\-. $/+%]+$/;

/**
 * Returns null when `value` can be encoded as `format`, otherwise the
 * translation key of the matching error message.
 */
export function barcodeValueError(format, value) {
  const v = String(value == null ? '' : value).trim();
  if (!v) return null; // emptiness is the required-rule's job
  switch (format) {
    case 'CODE39':
      return CODE39_RE.test(v) ? null : 'Code_Invalid_CODE39';
    case 'EAN13':
    case 'EAN8':
    case 'UPC': {
      const body = GTIN_BODY_LENGTH[format];
      if (new RegExp(`^\\d{${body}}$`).test(v)) return null;
      if (new RegExp(`^\\d{${body + 1}}$`).test(v) && gtinChecksumValid(v)) return null;
      return `Code_Invalid_${format}`;
    }
    default:
      return null; // CODE128 accepts any ASCII value
  }
}

/**
 * Generates a code valid for `format`. GTIN formats include the check digit
 * so the stored code equals the scanned value; others keep the legacy random
 * 8-digit number.
 */
export function generateBarcodeValue(format) {
  const body = GTIN_BODY_LENGTH[format];
  if (body) {
    const digits = randomDigits(body, true);
    return digits + gtinCheckDigit(digits);
  }
  // Legacy generateNumber(): random integer in [10^7, 10^8).
  return String(Math.floor(
    Math.pow(10, 7) + Math.random() * (Math.pow(10, 8) - Math.pow(10, 7) - 1)));
}
