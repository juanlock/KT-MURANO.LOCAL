/**
 * Small presentation helpers shared by the portal views (Tabler classes).
 */

export function brand() {
  const b = (typeof window !== 'undefined' && window.__PORTAL_APP__) || {};
  return {
    name: (b && b.name) || 'Client Portal',
    logo: (b && b.logo) || '',
    currency: (b && b.currency) || '',
  };
}

export function money(n, dash = false) {
  if (n == null || n === '') return dash ? '—' : '0.00';
  const formatted = Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const symbol = brand().currency;
  return symbol ? `${symbol} ${formatted}` : formatted;
}

export function number(n) {
  return Number(n || 0).toLocaleString('en-US');
}

/** Tabler tone (green/blue/yellow/red/…) for a status; `badge bg-<tone>-lt`. */
export function invoiceTone(status) {
  const v = (status || '').toLowerCase();
  if (v === 'paid' || v === 'completed') return 'green';
  if (v === 'partial') return 'blue';
  return 'yellow';
}
export function quotationTone(status) {
  const v = (status || '').toLowerCase();
  if (v === 'approved' || v === 'accepted' || v === 'completed') return 'green';
  if (v === 'rejected' || v === 'cancelled') return 'red';
  return 'yellow';
}
export function appointmentTone(status) {
  const v = (status || '').toLowerCase();
  if (v === 'completed' || v === 'delivered' || v === 'ready') return 'green';
  if (v === 'in_progress' || v === 'approved') return 'blue';
  if (v === 'cancelled' || v === 'declined') return 'red';
  return 'yellow';
}
export function contractTone(status) {
  const v = (status || '').toLowerCase();
  if (v === 'active' || v === 'signed') return 'green';
  if (v === 'expired' || v === 'cancelled' || v === 'terminated') return 'red';
  return 'yellow';
}

export const badge = (tone) => `badge bg-${tone}-lt`;

export function apiError(e, fallback) {
  const data = e && e.response && e.response.data ? e.response.data : e;
  return (data && data.message) || (e && e.message) || fallback;
}

export function initials(name) {
  return String(name || '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w.charAt(0).toUpperCase())
    .join('') || '?';
}
