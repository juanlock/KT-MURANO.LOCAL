/**
 * The quick date-range presets shared by Sales, Purchases, POS Sales, the
 * Payments report and the Dashboard (the button row + "Start date / to"
 * keycard). One list so every page offers the same buttons in the same order.
 * Weeks run Monday-Sunday, like the booking calendar.
 */
import dayjs from 'dayjs';
import { computed } from 'vue';

export const DATE_PRESETS = [
  { value: 'all', labelKey: 'All' },
  { value: 'year', labelKey: 'This_Year' },
  { value: 'month', labelKey: 'This_Month' },
  { value: 'week', labelKey: 'This_Week' },
  { value: 'today', labelKey: 'Today' },
  { value: 'last_week', labelKey: 'Last_Week' },
  { value: 'last_month', labelKey: 'Last_Month' },
  { value: 'last_year', labelKey: 'Last_Year' },
];

const mondayOf = d => d.subtract((d.day() + 6) % 7, 'day').startOf('day');

/** [from, to] as dayjs values for a preset ([null, null] for "all" / unknown). */
export function presetRange(preset, now = dayjs()) {
  switch (preset) {
    case 'year':
      return [now.startOf('year'), now.endOf('year')];
    case 'month':
      return [now.startOf('month'), now.endOf('month')];
    case 'week': {
      const m = mondayOf(now);
      return [m, m.add(6, 'day').endOf('day')];
    }
    case 'today':
      return [now.startOf('day'), now.endOf('day')];
    case 'last_week': {
      const m = mondayOf(now).subtract(7, 'day');
      return [m, m.add(6, 'day').endOf('day')];
    }
    case 'last_month': {
      const m = now.subtract(1, 'month');
      return [m.startOf('month'), m.endOf('month')];
    }
    case 'last_year': {
      const y = now.subtract(1, 'year');
      return [y.startOf('year'), y.endOf('year')];
    }
    default:
      return [null, null];
  }
}

/** Same as presetRange, as 'YYYY-MM-DD' strings ('' for open ends). */
export function presetDates(preset) {
  const [a, b] = presetRange(preset);
  return { from: a ? a.format('YYYY-MM-DD') : '', to: b ? b.format('YYYY-MM-DD') : '' };
}

/**
 * v-model for PresetRangeFilter on a page that keeps its dates as two
 * 'YYYY-MM-DD' strings (from / to, '' = open): read(): [from, to],
 * write(from, to). Null range <-> both ''.
 *   const range = rangeModel(() => [f.value.from, f.value.to], (a, b) => { f.value.from = a; f.value.to = b; });
 */
export function rangeModel(read, write) {
  return computed({
    get: () => {
      const [a, b] = read();
      return a || b ? [a || b, b || a] : null;
    },
    set: r => write(r ? r[0] : '', r ? r[1] : ''),
  });
}

/**
 * The preset a 'YYYY-MM-DD' range is ('all' when both ends are empty), or
 * null for a hand-edited custom range -- so a page whose Start/End dates were
 * edited shows no preset button active, and one typed back to e.g. this
 * month's dates lights "This Month" again.
 */
export function presetOf(from, to) {
  if (!from && !to) return 'all';
  const hit = DATE_PRESETS.find(p => {
    const d = presetDates(p.value);
    return p.value !== 'all' && d.from === from && d.to === to;
  });
  return hit ? hit.value : null;
}
