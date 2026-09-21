/**
 * Minimal fetch wrapper matching the legacy admin's axios setup:
 * baseURL /api/, cookies, and — critically — the X-XSRF-TOKEN header on EVERY
 * request (GETs included). The API uses Passport's cookie guard
 * (CreateFreshApiToken / laravel_token cookie), which rejects any request
 * missing a valid CSRF header with a 401. Axios sends this header
 * automatically from the XSRF-TOKEN cookie; fetch does not, so we replicate it.
 * Throws on non-2xx; a 401/419 redirects to the login page.
 */

const BASE = '/api/';

function readCookie(name) {
    const match = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : '';
}

function csrfHeaders() {
    const xsrf = readCookie('XSRF-TOKEN');
    if (xsrf) return { 'X-XSRF-TOKEN': xsrf };
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? { 'X-CSRF-TOKEN': meta.getAttribute('content') } : {};
}

async function request(method, url, { params, body, formData, raw } = {}) {
    let full = BASE + url.replace(/^\//, '');
    if (params) {
        const qs = new URLSearchParams();
        Object.entries(params).forEach(([k, v]) => {
            if (v !== undefined && v !== null) qs.append(k, v);
        });
        const s = qs.toString();
        if (s) full += (full.includes('?') ? '&' : '?') + s;
    }

    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...csrfHeaders(),
    };
    let payload;
    if (formData) {
        payload = formData;
    } else if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        payload = JSON.stringify(body);
    }

    const res = await fetch(full, {
        method,
        credentials: 'same-origin',
        headers,
        body: payload,
    });

    if (res.status === 401 || res.status === 419) {
        window.location.href = '/login';
        throw new Error('Unauthenticated');
    }
    if (!res.ok) {
        const err = new Error(`HTTP ${res.status}`);
        err.status = res.status;
        try { err.data = await res.json(); } catch (e) { /* not JSON */ }
        throw err;
    }
    if (res.status === 204) return raw ? { status: 204, data: null } : null;
    const data = await res.json();
    return raw ? { status: res.status, data } : data;
}

/** GET a binary response (PDF downloads) and save it via a temporary link. */
async function download(url, filename) {
    const res = await fetch(BASE + url.replace(/^\//, ''), {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', ...csrfHeaders() },
    });
    if (!res.ok) {
        const err = new Error(`HTTP ${res.status}`);
        err.status = res.status;
        throw err;
    }
    const blob = await res.blob();
    const href = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = href;
    link.setAttribute('download', filename);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.URL.revokeObjectURL(href);
}

/**
 * GET a binary response as a Blob, for previewing (rather than saving) a PDF.
 * The type is coerced so an error page returned as a blob still renders as
 * something rather than silently failing in an iframe.
 */
async function blob(url) {
    const res = await fetch(BASE + url.replace(/^\//, ''), {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', ...csrfHeaders() },
    });
    if (!res.ok) {
        const err = new Error(`HTTP ${res.status}`);
        err.status = res.status;
        throw err;
    }
    const raw = await res.blob();
    return raw.type === 'application/pdf' ? raw : new Blob([raw], { type: 'application/pdf' });
}

export default {
    get: (url, params) => request('GET', url, { params }),
    post: (url, body) => request('POST', url, { body }),
    postForm: (url, formData) => request('POST', url, { formData }),
    put: (url, body) => request('PUT', url, { body }),
    patch: (url, body) => request('PATCH', url, { body }),
    delete: (url) => request('DELETE', url),
    download,
    blob,
    /**
     * Same as the verbs above but resolves `{ status, data }` instead of the
     * bare body. The POS axios shim needs the HTTP status (its callers read
     * `response.status`), which the plain verbs discard.
     */
    raw: (method, url, opts = {}) => request(method, url, { ...opts, raw: true }),
};
