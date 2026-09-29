/**
 * Intranet module helpers shared by the employee view and Intranet
 * Management pages.
 */
import DOMPurify from 'dompurify';
import dayjs from 'dayjs';
import { getActingCompanyId } from '../../lib/actingCompany';
import http from '../../lib/http';
import { t } from '../../i18n';

/** Rich text from the API is purified server-side; sanitize again before v-html. */
export function safeHtml(html) {
    return DOMPurify.sanitize(html || '', { ADD_ATTR: ['target'], FORBID_TAGS: ['style', 'form', 'input'] });
}

export const POST_CATEGORIES = ['news', 'announcement', 'info'];
const CATEGORY_KEYS = { news: 'Intranet_Cat_News', announcement: 'Intranet_Cat_Announcement', info: 'Intranet_Cat_Info' };
const CATEGORY_COLORS = { news: 'blue', announcement: 'orange', info: 'green' };
export const categoryLabel = c => t(CATEGORY_KEYS[c] || 'Intranet_Cat_News');
export const categoryColor = c => CATEGORY_COLORS[c] || 'default';

export function formatBytes(n) {
    const v = Number(n) || 0;
    if (v < 1024) return `${v} B`;
    if (v < 1024 * 1024) return `${(v / 1024).toFixed(1)} KB`;
    return `${(v / 1024 / 1024).toFixed(1)} MB`;
}

export const fmtDate = d => (d ? dayjs(d).format('YYYY-MM-DD') : '');
export const fmtDateTime = d => (d ? dayjs(d).format('YYYY-MM-DD HH:mm') : '');

/** "Mon 12 Oct, 10:00 – 12:00" style label for an event. */
export function eventWhen(ev) {
    if (!ev) return '';
    const s = dayjs(ev.starts_at);
    const e = ev.ends_at ? dayjs(ev.ends_at) : null;
    if (ev.all_day) {
        return e && !e.isSame(s, 'day') ? `${s.format('ddd D MMM')} – ${e.format('ddd D MMM YYYY')}` : s.format('ddd D MMM YYYY');
    }
    if (!e) return s.format('ddd D MMM YYYY, HH:mm');
    return e.isSame(s, 'day')
        ? `${s.format('ddd D MMM YYYY, HH:mm')} – ${e.format('HH:mm')}`
        : `${s.format('ddd D MMM, HH:mm')} – ${e.format('ddd D MMM YYYY, HH:mm')}`;
}

export const personName = u => (u ? (`${u.firstname || ''} ${u.lastname || ''}`.trim() || u.username || '') : '');

/** Download a private intranet file through the authenticated endpoint. */
export function downloadFile(file) {
    const cid = getActingCompanyId();
    const qs = cid ? `?company_id=${encodeURIComponent(cid)}` : '';
    return http.download(`intranet/files/${file.id}/download${qs}`, file.original_name || file.title || 'file');
}

export const fileIconColor = ext => ({
    pdf: '#e5484d', doc: '#2b6cd4', docx: '#2b6cd4', odt: '#2b6cd4', rtf: '#2b6cd4', txt: '#6b7280',
    xls: '#1f9254', xlsx: '#1f9254', ods: '#1f9254', csv: '#1f9254',
    ppt: '#d9622b', pptx: '#d9622b', odp: '#d9622b', zip: '#8b5cf6',
}[String(ext || '').toLowerCase()] || '#0ea5e9');

/** Normalise an API error into a message. */
export function apiError(e, fallback) {
    const d = e?.data;
    if (d?.errors) return Object.values(d.errors).flat().join(' ');
    return d?.message || fallback || t('InvalidData');
}
