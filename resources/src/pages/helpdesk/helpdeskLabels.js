// Shared labels/colours for the Helpdesk screens.
export const STATUS = {
  open: { label: 'Open', color: 'blue' },
  pending: { label: 'Waiting on requester', color: 'orange' },
  resolved: { label: 'Resolved', color: 'green' },
  closed: { label: 'Closed', color: 'default' },
};
export const PRIORITY = {
  low: { label: 'Low', color: 'default' },
  normal: { label: 'Normal', color: 'cyan' },
  high: { label: 'High', color: 'orange' },
  urgent: { label: 'Urgent', color: 'red' },
};
export const REQUESTER = {
  employee: 'Employee',
  customer: 'Customer',
  online_customer: 'Online customer',
  supplier: 'Supplier',
  guest: 'Guest',
};
export const SOURCE = {
  app: 'App', portal: 'Client portal', store: 'Online store', public_form: 'Public form', email: 'Email', admin: 'Agent',
};
export const options = map => Object.entries(map).map(([value, v]) => ({ value, label: typeof v === 'string' ? v : v.label }));
