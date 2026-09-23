/**
 * The globally-selected "acting company" (SuperAdmin's company switcher in
 * AppTopbar.vue). A plain leaf module, not a Pinia store, specifically so
 * lib/http.js can read it without a circular import (stores/company.js
 * imports http.js to fetch the company list; http.js needs to read this
 * value on every request) -- stores/company.js wraps this for reactivity,
 * this module is the actual source of truth.
 *
 * Every request goes through resolveActingCompanyId() server-side, which
 * only ever honors company_id for a real SuperAdmin and silently ignores it
 * for anyone else -- so it's safe for http.js to attach this to every
 * outgoing request unconditionally; it's a no-op for non-SuperAdmin users
 * (this value is only ever set by the switcher, which only renders for one).
 */

const STORAGE_KEY = 'acting_company_id';

let companyId = null;
try {
    const saved = window.localStorage.getItem(STORAGE_KEY);
    if (saved) companyId = Number(saved) || null;
} catch (e) { /* private browsing / storage blocked */ }

export function getActingCompanyId() {
    return companyId;
}

export function setActingCompanyId(id) {
    companyId = id || null;
    try {
        if (companyId) window.localStorage.setItem(STORAGE_KEY, String(companyId));
        else window.localStorage.removeItem(STORAGE_KEY);
    } catch (e) { /* private browsing / storage blocked */ }
}
