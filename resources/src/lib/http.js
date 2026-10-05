/**
 * Minimal fetch wrapper matching the legacy admin's axios setup:
 * baseURL /api/, cookies, and — critically — the X-XSRF-TOKEN header on EVERY
 * request (GETs included). The API uses Passport's cookie guard
 * (CreateFreshApiToken / laravel_token cookie), which rejects any request
 * missing a valid CSRF header with a 401. Axios sends this header
 * automatically from the XSRF-TOKEN cookie; fetch does not, so we replicate it.
 * Throws on non-2xx; a 401/419 redirects to the login page.
 */

import { getActingCompanyId } from './actingCompany';

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
    // Global company switcher (SuperAdmin only -- a no-op default for
    // everyone else): attaches to every request WITHOUT overriding a
    // caller's own explicit company_id (e.g. a "which company should this
    // new user belong to" form field takes precedence over the switcher).
    //
    // Always goes on the query string (Laravel's $request->input() merges
    // query + body, so the backend picks it up regardless of method) --
    // NOT just for GET/DELETE. A bodyless action call like
    // `http.post('languages_setting/1/set-default')` has no object body to
    // merge into, so query-string-only used to silently drop company_id on
    // exactly these calls, making the write fall back to the acting user's
    // own company instead of the one selected in the header.
    const actingCompanyId = getActingCompanyId();
    // An EMPTY company_id from the caller (e.g. a list's "Company: All"
    // filter left blank) means "no choice", not "every company" -- it must
    // not override the switcher. Only a real value takes precedence.
    const blank = v => v === undefined || v === null || v === '';
    if (actingCompanyId && params && blank(params.company_id)) {
        params = { ...params };
        delete params.company_id;
    }
    if (actingCompanyId && body && typeof body === 'object' && !Array.isArray(body) && !(body instanceof FormData) && blank(body.company_id)) {
        body = { ...body };
        delete body.company_id;
    }
    if (actingCompanyId) {
        params = { company_id: actingCompanyId, ...(params || {}) };
        if (formData instanceof FormData) {
            if (!formData.has('company_id')) formData.append('company_id', actingCompanyId);
        } else if (body !== undefined && body !== null && typeof body === 'object' && !Array.isArray(body)) {
            body = { company_id: actingCompanyId, ...body };
        }
    }

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
        // Always the server's current answer: a browser-cached GET showed
        // old data (e.g. an email reopened after it was recorded).
        cache: 'no-store',
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
 * something rather than silently failing in an iframe. keepType: return the
 * file's own type (an image / any attachment preview), not coerced to PDF.
 */
async function blob(url, keepType = false) {
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
    if (keepType) return raw;
    return raw.type === 'application/pdf' ? raw : new Blob([raw], { type: 'application/pdf' });
}

/**
 * POST to a WEB route (session + CSRF, not /api/) -- e.g. the POS cashier PIN
 * switch, which has to change the browser session's user. Throws like
 * request() (err.status / err.data) but never redirects on 401/419.
 */
async function webPost(url, body) {
    const res = await fetch('/' + url.replace(/^\//, ''), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...csrfHeaders(),
        },
        body: JSON.stringify(body || {}),
    });
    let data = null;
    try { data = await res.json(); } catch (e) { /* not JSON */ }
    if (!res.ok) {
        const err = new Error(`HTTP ${res.status}`);
        err.status = res.status;
        err.data = data;
        throw err;
    }
    return data;
}

export default {
    webPost,
    get: (url, params) => request('GET', url, { params }),
    post: (url, body) => request('POST', url, { body }),
    postForm: (url, formData) => request('POST', url, { formData }),
    put: (url, body) => request('PUT', url, { body }),
    patch: (url, body) => request('PATCH', url, { body }),
    delete: (url, params) => request('DELETE', url, { params }),
    download,
    blob,
    /**
     * Same as the verbs above but resolves `{ status, data }` instead of the
     * bare body. The POS axios shim needs the HTTP status (its callers read
     * `response.status`), which the plain verbs discard.
     */
    raw: (method, url, opts = {}) => request(method, url, { ...opts, raw: true }),
};
