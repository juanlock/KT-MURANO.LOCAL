/**
 * Browser tab title = "<page title> — <suffix>". The page title comes from
 * the route (router/index.js); the suffix is the acting company's own
 * Appearance -> "Page title suffix" (get_user_auth -> user.page_title_suffix,
 * applied by stores/auth.js). The blade's window.__PAGE_TITLE_SUFFIX__ is only
 * the pre-login fallback -- it's the install-wide template row, not the
 * company's, which is why the tab used to show a suffix that didn't match
 * what the Appearance form saved.
 *
 * The route title is English text used as a translation key (same loose
 * lookup as the sidebar: "Admit Student", Admit_Student, any case), so the
 * tab follows the app language; re-rendered when the language changes.
 */
import { watch } from 'vue';
import { i18n, looseTranslation } from '../i18n';

let pageTitle = '';
let suffix = window.__PAGE_TITLE_SUFFIX__ || '';

function render() {
    if (!pageTitle) return;
    const title = looseTranslation(pageTitle) ?? pageTitle;
    document.title = suffix ? `${title} — ${suffix}` : title;
}

// The language, or its texts arriving (the first page's title is set before
// /api/translations answers).
watch(() => [i18n.global.locale.value, i18n.global.messages.value[i18n.global.locale.value]], () => render());

export function setPageTitle(title) {
    pageTitle = title || '';
    render();
}

export function setTitleSuffix(value) {
    suffix = value || '';
    render();
}
