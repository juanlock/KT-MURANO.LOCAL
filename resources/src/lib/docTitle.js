/**
 * Browser tab title = "<page title> — <suffix>". The page title comes from
 * the route (router/index.js); the suffix is the acting company's own
 * Appearance -> "Page title suffix" (get_user_auth -> user.page_title_suffix,
 * applied by stores/auth.js). The blade's window.__PAGE_TITLE_SUFFIX__ is only
 * the pre-login fallback -- it's the install-wide template row, not the
 * company's, which is why the tab used to show a suffix that didn't match
 * what the Appearance form saved.
 */
let pageTitle = '';
let suffix = window.__PAGE_TITLE_SUFFIX__ || '';

function render() {
    if (!pageTitle) return;
    document.title = suffix ? `${pageTitle} — ${suffix}` : pageTitle;
}

export function setPageTitle(title) {
    pageTitle = title || '';
    render();
}

export function setTitleSuffix(value) {
    suffix = value || '';
    render();
}
