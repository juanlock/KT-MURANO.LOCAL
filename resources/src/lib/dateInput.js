/**
 * Typed dates (user's rule, 2026-10-25): every date field shows the company's
 * format (settings.date_format, from its country: Peru DD-MM-YYYY, USA
 * MM-DD-YYYY) and accepts what people type quickly -- with or without
 * separators, 2- or 4-digit year: 25102026, 251026, 25/10/26, 5-3-2026.
 * The stored value (value-format) never changes.
 *
 * pickerFormat(companyFormat, attrs) -> the `format` prop for a DatePicker:
 * [display format, ...other formats accepted when typing].
 */
const ALT = {
  DMY: ['DD-MM-YYYY', 'DD/MM/YYYY', 'DD.MM.YYYY', 'D-M-YYYY', 'D/M/YYYY', 'DDMMYYYY', 'DD-MM-YY', 'DD/MM/YY', 'D/M/YY', 'D-M-YY', 'DDMMYY'],
  MDY: ['MM-DD-YYYY', 'MM/DD/YYYY', 'M/D/YYYY', 'M-D-YYYY', 'MMDDYYYY', 'MM-DD-YY', 'MM/DD/YY', 'M/D/YY', 'M-D-YY', 'MMDDYY'],
  YMD: ['YYYY-MM-DD', 'YYYY/MM/DD', 'YYYYMMDD'],
};

export function dateOrder(fmt) {
  const f = String(fmt || 'YYYY-MM-DD').toUpperCase();
  if (f.startsWith('D')) return 'DMY';
  if (f.startsWith('M')) return 'MDY';
  return 'YMD';
}

/** What to show as the empty field's hint: dd-mm-aaaa / mm-dd-yyyy (+ hh:mm). */
export function datePlaceholder(fmt, withTime, es) {
  const order = dateOrder(fmt);
  const y = es ? 'aaaa' : 'yyyy';
  const base = order === 'DMY' ? `dd-mm-${y}` : order === 'MDY' ? `mm-dd-${y}` : `${y}-mm-dd`;
  return withTime ? `${base} hh:mm` : base;
}

const attr = (attrs, ...names) => {
  for (const n of names) if (attrs[n] !== undefined) return attrs[n];
  return undefined;
};

/** True when the picker is a plain date / date-time one (not month, year, week, time). */
export function isDayPicker(attrs) {
  const picker = attr(attrs, 'picker');
  return !picker || picker === 'date';
}

export function hasTime(attrs) {
  const st = attr(attrs, 'showTime', 'show-time');
  const fmt = attr(attrs, 'format');
  return (st !== undefined && st !== false) || (typeof fmt === 'string' && /H/.test(fmt));
}

export function pickerFormat(companyFormat, attrs) {
  const orig = attr(attrs, 'format');
  if (!isDayPicker(attrs) || typeof orig === 'function') return orig;
  // A time-only format on a date picker (rare): leave it.
  if (typeof orig === 'string' && /H/.test(orig) && !/D/.test(orig)) return orig;
  const order = dateOrder(companyFormat);
  const display = String(companyFormat || 'YYYY-MM-DD');
  if (!hasTime(attrs)) return [...new Set([display, ...ALT[order], 'YYYY-MM-DD'])];
  const secs = typeof orig === 'string' && /ss/.test(orig);
  const time = secs ? ' HH:mm:ss' : ' HH:mm';
  const typed = [...ALT[order].map(f => f + ' HH:mm'), ...ALT[order].map(f => f + ' H:mm'), ...ALT[order].map(f => f + ' HHmm')];
  return [...new Set([display + time, ...typed, 'YYYY-MM-DD HH:mm:ss', 'YYYY-MM-DD HH:mm'])];
}
