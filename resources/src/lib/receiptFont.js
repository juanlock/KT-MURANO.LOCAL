/**
 * Custom POS receipt font (Settings → System → POS Receipt).
 *
 * Print popups load only /css/pos_print.css, which flattens every element to
 * 12px at print time with element selectors — so a custom size/family must be
 * forced with !important to win. Inter (the pre-Vue3 admin font), Ubuntu (the
 * legacy pos_print.css stack) and Roboto are self-hosted under /fonts via
 * /css/receipt-fonts.css — no external request, POS keeps printing offline;
 * anything else falls back through its CSS stack to installed fonts.
 */

const LOCAL_FONTS = ['Inter', 'Ubuntu', 'Roboto'];
const LOCAL_FONTS_CSS = '/css/receipt-fonts.css';

// Keep to a charset safe for injection into <style> tags.
export function sanitizeFontFamily(value) {
  return String(value || '').trim().replace(/[^a-zA-Z0-9 ,'\-]/g, '');
}

/**
 * Head markup (<link> + <style>) for a print window. `scope` is the selector
 * the receipt lives under ('#invoice-POS' or 'body' depending on whether the
 * popup recreates the wrapper). Returns '' when both settings are default.
 */
export function receiptFontHeadTags(posSettings, scope = '#invoice-POS') {
  const ps = posSettings || {};
  const family = sanitizeFontFamily(ps.receipt_font_family);
  const size = Number(ps.receipt_font_size);
  let tags = '';
  if (LOCAL_FONTS.some(name => family.includes(name))) {
    tags += `<link rel="stylesheet" href="${LOCAL_FONTS_CSS}">`;
  }
  let css = '';
  if (family) css += `${scope}, ${scope} * { font-family: ${family} !important; }`;
  if (size >= 8 && size <= 24) css += `${scope}, ${scope} * { font-size: ${size}px !important; line-height: 1.5 !important; }`;
  if (css) tags += `<style>${css}</style>`;
  return tags;
}

/**
 * Fire `fn` once the popup's webfonts have applied (or after `fallbackMs` if
 * fonts.ready never resolves). Calling print() on a bare timer can snapshot
 * the page before the woff2 loads, printing the fallback font instead of the
 * one chosen in Settings → POS Receipt.
 */
export function whenPrintFontsReady(win, fn, fallbackMs = 1200) {
  let done = false;
  const once = () => { if (!done) { done = true; fn(); } };
  try {
    const fonts = win.document && win.document.fonts;
    if (fonts && fonts.ready && typeof fonts.ready.then === 'function') {
      fonts.ready.then(() => setTimeout(once, 50));
    }
  } catch (e) { /* fall through to the timer */ }
  setTimeout(once, fallbackMs);
}
