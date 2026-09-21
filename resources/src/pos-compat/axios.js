/**
 * Axios-shaped adapter over lib/http for the POS port: the 6k-line legacy
 * script calls `axios.get(url).then(r => r.data)` everywhere, so responses
 * are wrapped back into `{ status, data }` (http.raw keeps the HTTP status,
 * which offline-sync callers read as `response.status`). Supports the config
 * subset POS uses:
 * { params }, { responseType: 'blob' }, { timeout } (ignored — fetch has no
 * trivial timeout and the legacy value was advisory).
 */
import http from '../lib/http';

function csrfHeaders() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) } : {};
}

async function blobGet(url) {
    const res = await fetch('/api/' + url.replace(/^\//, ''), {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', ...csrfHeaders() },
    });
    if (!res.ok) {
        const err = new Error(`HTTP ${res.status}`);
        err.response = { status: res.status };
        throw err;
    }
    return { status: res.status, data: await res.blob() };
}

function wrapError(e) {
    // Legacy reads error.response.data.* — mirror that shape.
    e.response = e.response || { status: e.status, data: e.data };
    throw e;
}

export default {
    async get(url, config = {}) {
        if (config.responseType === 'blob') return blobGet(url);
        try {
            return await http.raw('GET', url, { params: config.params });
        } catch (e) { wrapError(e); }
    },
    async post(url, body, config = {}) {
        try {
            return body instanceof FormData
                ? await http.raw('POST', url, { formData: body })
                : await http.raw('POST', url, { body });
        } catch (e) { wrapError(e); }
    },
    async put(url, body) {
        try {
            return await http.raw('PUT', url, { body });
        } catch (e) { wrapError(e); }
    },
    async delete(url) {
        try {
            return await http.raw('DELETE', url);
        } catch (e) { wrapError(e); }
    },
};
