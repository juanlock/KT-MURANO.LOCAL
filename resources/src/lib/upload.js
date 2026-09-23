/**
 * Multipart upload with progress, XHR-based because fetch has no upload
 * progress. Mirrors the legacy import pages' axios setup exactly:
 * validateStatus: () => true — the promise RESOLVES on any HTTP status
 * (422 included) with { status, data }, and only rejects on network failure.
 * Same /api/ base + CSRF headers as lib/http.
 */

import { getActingCompanyId } from './actingCompany';

const BASE = '/api/';

function readCookie(name) {
    const match = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : '';
}

export function uploadForm(url, formData, onProgress) {
    return new Promise((resolve, reject) => {
        // Global company switcher (SuperAdmin only): this utility never
        // went through lib/http.js, so it never got the acting company_id
        // -- every upload+save through here silently fell back to the
        // logged-in SuperAdmin's own company instead of whichever company
        // was selected in the header. Attach it on the query string (the
        // backend reads $request->input(), which merges query + body) and
        // on the form body itself, same as lib/http.js does.
        const actingCompanyId = getActingCompanyId();
        let finalUrl = url.replace(/^\//, '');
        if (actingCompanyId) {
            if (formData instanceof FormData && !formData.has('company_id')) {
                formData.append('company_id', actingCompanyId);
            }
            finalUrl += (finalUrl.includes('?') ? '&' : '?') + 'company_id=' + encodeURIComponent(actingCompanyId);
        }

        const xhr = new XMLHttpRequest();
        xhr.open('POST', BASE + finalUrl);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        const xsrf = readCookie('XSRF-TOKEN');
        if (xsrf) xhr.setRequestHeader('X-XSRF-TOKEN', xsrf);
        xhr.withCredentials = true;

        if (xhr.upload && onProgress) {
            xhr.upload.onprogress = (pe) => {
                if (pe && pe.total) onProgress(Math.round((pe.loaded * 100) / pe.total));
            };
        }

        xhr.onload = () => {
            let data = null;
            try { data = JSON.parse(xhr.responseText); } catch (e) { data = xhr.responseText; }
            resolve({ status: xhr.status, data });
        };
        xhr.onerror = () => reject(new Error('Network error. Please try again.'));
        xhr.send(formData);
    });
}
