/**
 * The quick date-range presets shared by Sales, Purchases, POS Sales, the
 * Payments report and the Dashboard (the button row + "Start date / to"
 * keycard). One list so every page offers the same buttons in the same order.
 * Weeks run Monday-Sunday, like the booking calendar.
 */
import dayjs from 'dayjs';

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
