import { createI18n } from 'vue-i18n';

/**
 * Translations come from the SAME source as the legacy admin:
 *   GET /api/translations/{locale}  ->  flat { key: value } map (public route)
 * and the active language lives in localStorage under 'language' — the same key
 * the Vue 2 app uses, so switching language in either app carries over.
 *
 * Keys are the legacy underscore style: $t('Search_this_table').
 */

export const SUPPORTED_LOCALES = [
    { value: 'en', label: 'English', flag: '🇬🇧' },
    { value: 'fr', label: 'Français', flag: '🇫🇷' },
    { value: 'es', label: 'Español', flag: '🇪🇸' },
    { value: 'ar', label: 'العربية', flag: '🇸🇦' },
];

const RTL_LOCALES = ['ar'];

export function isRtlLocale(locale) {
    return RTL_LOCALES.includes(locale);
}

export function storedLocale() {
    try {
        return window.localStorage.getItem('language') || 'en';
    } catch (e) {
        return 'en';
    }
}

// Case-insensitive rescue index: locale -> { lowercased key: value }. The
// legacy key set mixes casings ('warehouse' vs 'Warehouse'); when a lookup
// misses only because of casing, serve the other casing's translation instead
// of leaking the raw key.
const lowerIndex = new Map();

function indexLocale(locale, messages) {
    const idx = Object.create(null);
    for (const k of Object.keys(messages || {})) idx[k.toLowerCase()] = messages[k];
    lowerIndex.set(locale, idx);
}

export const i18n = createI18n({
    legacy: false,          // Composition API mode
    globalInjection: true,  // keep $t() available in templates
    locale: storedLocale(),
    fallbackLocale: 'en',
    messages: {},
    missingWarn: false,
    fallbackWarn: false,
    // Runs only on a miss; active locale's other casing first, then English's.
    missing: (locale, key) => {
        const lk = String(key).toLowerCase();
        for (const loc of [locale, 'en']) {
            const idx = lowerIndex.get(loc);
            if (idx && idx[lk] !== undefined) return idx[lk];
        }
        return undefined;
    },
});

const loaded = new Set();

// Offline fallback: last successfully fetched messages per locale. The API
// stays the source of truth (fetched with cache: 'no-store' every boot); this
// copy is only read when that fetch fails, so an offline reload of the POS
// doesn't render raw i18n keys.
const CACHE_PREFIX = 'stocky_i18n_cache_v1_';

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
    } catch (e) { /* quota/private mode — offline fallback just won't exist */ }
}

/**
 * Fetches a locale's messages once and activates it. Never throws: on failure
 * it falls back to the last cached copy, and only when there is no cache does
 * $t() echo the key (which stays readable because the legacy keys are
 * English-ish, e.g. 'Search_this_table').
 */
export async function loadLocale(locale) {
    if (!loaded.has(locale)) {
        let messages = null;
        try {
            const res = await fetch(`/api/translations/${locale}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                // Translations are edited at runtime (admin UI, seeder); a stale
                // HTTP cache would keep showing old values after a fix.
                cache: 'no-store',
            });
            if (res.ok) {
                messages = await res.json();
                writeCachedMessages(locale, messages);
            }
        } catch (e) {
            console.warn(`[stocky-next] could not load translations for "${locale}" — trying offline cache`);
        }
        if (!messages) messages = readCachedMessages(locale);
        if (messages) {
            i18n.global.setLocaleMessage(locale, messages || {});
            indexLocale(locale, messages);
            loaded.add(locale);
        }
    }

    i18n.global.locale.value = locale;
    applyDirection(locale);
    try {
        window.localStorage.setItem('language', locale);
    } catch (e) { /* ignore storage errors */ }
}

export function applyDirection(locale) {
    const dir = isRtlLocale(locale) ? 'rtl' : 'ltr';
    document.documentElement.setAttribute('dir', dir);
    document.documentElement.setAttribute('lang', locale);
}

/** Translate with a human-readable fallback when a key is missing. */
export function t(key, fallback) {
    const out = i18n.global.t(key);
    return out === key && fallback ? fallback : out;
}
