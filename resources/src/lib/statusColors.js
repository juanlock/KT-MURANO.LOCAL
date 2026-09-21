/**
 * Ant tag colours for the legacy status vocabularies, shared by the sales/
 * purchases documents and their reports.
 */

/** Document status: completed/received, pending, ordered, cancelled. */
export function docStatusColor(status) {
    const s = String(status || '').toLowerCase();
    if (s.includes('complet') || s.includes('received')) return 'success';
    if (s.includes('pending')) return 'warning';
    if (s.includes('ordered') || s.includes('sent')) return 'processing';
    if (s.includes('cancel')) return 'error';
    return 'default';
}

/** Payment status: paid, partial, unpaid. */
export function payStatusColor(status) {
    const s = String(status || '').toLowerCase();
    if (s.includes('paid') && !s.includes('unpaid')) return 'success';
    if (s.includes('partial')) return 'warning';
    if (s.includes('unpaid')) return 'error';
    return 'default';
}
