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
/** "Monthly" / "Every 2 months". */
export function billingLabel(c) {
  const n = Number(c.billing_interval) || 1;
  return n === 1 ? SINGLE[c.billing_frequency] || c.billing_frequency : `Every ${n} ${PLURAL[c.billing_frequency] || c.billing_frequency}`;
}
