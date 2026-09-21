/**
 * Marketing vocabulary shared by the module's pages. Channel and status
 * values are backend enums (campaigns.type / campaigns.status); labels are
 * displayed the way legacy shows them: channels as brand names, statuses as
 * the raw value with underscores replaced (no i18n keys exist for them).
 */

export const CHANNELS = ['sms', 'email', 'whatsapp'];

export const CAMPAIGN_STATUSES = ['draft', 'scheduled', 'sending', 'sent', 'failed', 'cancelled'];

const CHANNEL_LABELS = { sms: 'SMS', email: 'Email', whatsapp: 'WhatsApp' };

export function channelLabel(type) {
    return CHANNEL_LABELS[type] || type || '—';
}

/** Legacy's format_label(): "not_started" → "not started". */
export function plainLabel(v) {
    return v ? String(v).replace(/_/g, ' ') : '—';
}

const STATUS_COLORS = {
    draft: 'default',
    scheduled: 'blue',
    sending: 'orange',
    sent: 'green',
    failed: 'red',
    cancelled: 'default',
};

export function campaignStatusColor(status) {
    return STATUS_COLORS[status] || 'default';
}

const RECIPIENT_COLORS = {
    pending: 'orange',
    sent: 'green',
    delivered: 'green',
    failed: 'red',
    skipped: 'default',
};

export function recipientStatusColor(status) {
    return RECIPIENT_COLORS[status] || 'default';
}

/** Statuses a campaign can be edited/sent in (legacy canEdit/canSend). */
export function campaignEditable(status) {
    return ['draft', 'scheduled', 'failed', 'cancelled'].includes(status);
}

export function campaignSendable(status) {
    return ['draft', 'scheduled', 'failed'].includes(status);
}

/** Placeholders the dispatcher replaces per recipient. */
export const PERSONALIZATION_VARIABLES = [
    '{customer_name}', '{phone}', '{email}', '{last_purchase}', '{total_spent}',
];
