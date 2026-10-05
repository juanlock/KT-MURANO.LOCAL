/** Shared labels for Sales > Sales Contracts. */
export const STATUS_COLORS = { draft: 'default', active: 'green', expired: 'orange', cancelled: 'red' };
export const STATUS_OPTIONS = [
  { value: 'draft', label: 'Draft' },
  { value: 'active', label: 'Active' },
  { value: 'expired', label: 'Expired' },
  { value: 'cancelled', label: 'Cancelled' },
];
export const statusLabel = s => (STATUS_OPTIONS.find(o => o.value === s) || {}).label || s;

export const FREQUENCY_OPTIONS = [
  { value: 'weekly', label: 'Week' },
  { value: 'monthly', label: 'Month' },
  { value: 'quarterly', label: 'Quarter' },
  { value: 'yearly', label: 'Year' },
];
const PLURAL = { weekly: 'weeks', monthly: 'months', quarterly: 'quarters', yearly: 'years' };
const SINGLE = { weekly: 'Weekly', monthly: 'Monthly', quarterly: 'Quarterly', yearly: 'Yearly' };
const WEEKDAYS = ['Mondays', 'Tuesdays', 'Wednesdays', 'Thursdays', 'Fridays', 'Saturdays', 'Sundays'];
/** "on day 1" / "last day of the month" / "on Fridays" ('' for the same day as the first invoice). */
export function billingDayLabel(c) {
  const day = Number(c.billing_day) || 0;
  if (c.billing_day_mode === 'first') return 'first day of the month';
  if (c.billing_day_mode === 'last') return 'last day of the month';
  if (c.billing_day_mode === 'day' && day) return c.billing_frequency === 'weekly' ? `on ${WEEKDAYS[day - 1] || ''}` : `on day ${day}`;
  return '';
}
/** "Monthly" / "Every 2 months", plus the invoice day and count when set ("Monthly · on day 1 · 5 invoices"). */
export function billingLabel(c) {
  const n = Number(c.billing_interval) || 1;
  const base = n === 1 ? SINGLE[c.billing_frequency] || c.billing_frequency : `Every ${n} ${PLURAL[c.billing_frequency] || c.billing_frequency}`;
  const count = Number(c.billing_count) || 0;
  return [base, billingDayLabel(c), count ? `${count} invoice${count === 1 ? '' : 's'}` : ''].filter(Boolean).join(' · ');
}
