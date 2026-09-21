import { createI18n } from 'vue-i18n';
import http from './lib/http';

/**
 * Client Portal i18n.
 *
 * Messages live in resources/lang/{locale}/portal.php (one file per locale,
 * shared with the portal API's own messages) and are fetched as a flat map from
 *   GET /api/portal/translations/{locale}
 *
 * The active locale is resolved by the server (SetPortalLocale middleware:
 * session > saved client preference > cookie > app default) and injected into
 * portal.blade.php as window.__PORTAL_LOCALE__; localStorage only serves as
 * the offline hint. Switching calls POST /api/portal/locale so Blade, invoice
 * PDFs and API error messages follow the same language.
 */

const FALLBACK_LOCALES = { en: 'English', fr: 'Français', es: 'Español', ar: 'العربية' };

export const SUPPORTED_LOCALES =
  window.__PORTAL_LOCALES__ && typeof window.__PORTAL_LOCALES__ === 'object' && Object.keys(window.__PORTAL_LOCALES__).length
    ? window.__PORTAL_LOCALES__
    : FALLBACK_LOCALES;

const RTL_LOCALES = ['ar'];
const STORAGE_KEY = 'portal_language';
const CACHE_PREFIX = 'portal_i18n_cache_v1_';

export function isRtlLocale(locale) {
  return RTL_LOCALES.includes(locale);
}

export function isSupported(locale) {
  return !!locale && Object.prototype.hasOwnProperty.call(SUPPORTED_LOCALES, locale);
}

export function initialLocale() {
  const fromServer = window.__PORTAL_LOCALE__;
  if (isSupported(fromServer)) return fromServer;
  try {
    const stored = window.localStorage.getItem(STORAGE_KEY);
    if (isSupported(stored)) return stored;
  } catch (e) { /* storage unavailable */ }
  return 'en';
}

export const i18n = createI18n({
  legacy: false,          // Composition API mode
  globalInjection: true,  // $t() in templates / this.$t in Options API
  locale: initialLocale(),
  fallbackLocale: 'en',
  messages: {},
  missingWarn: false,
  fallbackWarn: false,
  silentTranslationWarn: true,
  silentFallbackWarn: true,
});

function readCachedMessages(locale) {
  try {
    const raw = window.localStorage.getItem(CACHE_PREFIX + locale);
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? parsed : null;
  } catch (e) {
    return null;
  }
}

function writeCachedMessages(locale, messages) {
  try {
    window.localStorage.setItem(CACHE_PREFIX + locale, JSON.stringify(messages || {}));
  } catch (e) { /* quota / private mode — offline fallback just won't exist */ }
}

export function applyDirection(locale) {
  document.documentElement.setAttribute('dir', isRtlLocale(locale) ? 'rtl' : 'ltr');
  document.documentElement.setAttribute('lang', locale);
}

const loaded = new Set();

/**
 * Fetches a locale's messages once and activates it. Never throws: on a failed
 * fetch it falls back to the last cached copy; with no cache $t() echoes the
 * key (the keys are readable English-ish slugs, e.g. 'loading_invoices').
 */
export async function loadLocale(locale) {
  if (!isSupported(locale)) locale = 'en';

  if (!loaded.has(locale)) {
    let messages = null;
    try {
      const { data } = await http.get(`/portal/translations/${locale}`);
      if (data && typeof data === 'object') {
        messages = data;
        writeCachedMessages(locale, data);
      }
    } catch (e) {
      messages = null;
    }
    if (!messages) messages = readCachedMessages(locale);
    if (messages) {
      i18n.global.setLocaleMessage(locale, messages);
      loaded.add(locale);
    }
  }

  i18n.global.locale.value = locale;
  applyDirection(locale);
  try {
    window.localStorage.setItem(STORAGE_KEY, locale);
  } catch (e) { /* ignore storage errors */ }
  return locale;
}

/**
 * User-initiated switch: update the UI immediately, then persist the choice
 * server-side (session + cookie, and the client's saved preference when
 * signed in). A failed POST keeps the local choice for this page load.
 */
export async function setLocale(locale) {
  if (!isSupported(locale)) return;
  await loadLocale(locale);
  try {
    await http.post('/portal/locale', { locale });
  } catch (e) { /* offline or throttled — local switch already applied */ }
}

export function currentLocale() {
  return i18n.global.locale.value;
}

/**
 * Translate an enum-ish API value (status, type, job_type) through a prefixed
 * key, falling back to the raw value when no translation exists.
 *   enumLabel('status_', 'in_progress') -> $t('status_in_progress')
 */
export function enumLabel(prefix, value) {
  if (value === null || value === undefined || value === '') return '';
  const key = prefix + String(value).toLowerCase().trim().replace(/[\s-]+/g, '_');
  return i18n.global.te(key) ? i18n.global.t(key) : String(value);
}
