/**
 * Shared formatting for service job tasks (pages/service/DailyTasks.vue and
 * components/TaskCard.vue).
 */
export function statusColor(s) {
  return { completed: 'success', in_progress: 'processing', pending: 'warning' }[s] || 'default';
}

export function statusLabel(s) {
  return { in_progress: 'In progress', completed: 'Completed', pending: 'Pending' }[s] || (s || '').replace('_', ' ');
}

/** "All day", "07:00", or "07:00 – 09:30" (same-day end). */
export function timeLabel(t) {
  if (t.all_day || !t.scheduled_date || String(t.scheduled_date).slice(11, 16) === '00:00') return 'All day';
  const from = String(t.scheduled_date).slice(11, 16);
  const to = t.scheduled_end_date && String(t.scheduled_end_date).slice(0, 10) === String(t.scheduled_date).slice(0, 10)
    ? String(t.scheduled_end_date).slice(11, 16) : '';
  return to && to !== '00:00' ? `${from} – ${to}` : from;
}

export function address(t) {
  return [t.client_address, t.client_city].filter(Boolean).join(', ');
}

export function operatorLabel(t) {
  return t.assign_all ? 'All operators' : (t.technician || 'Not assigned');
}
