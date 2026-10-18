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
            baseMessages.set(locale, messages);
            applyProductLabels(locale);
        }
    }

    i18n.global.locale.value = locale;
    applyDirection(locale);
    try {
        window.localStorage.setItem('language', locale);
    } catch (e) { /* ignore storage errors */ }
}

/**
 * Per-company names for the product "Group Name" / "Name" fields (System
 * Settings -> Defaults -> Products; get_user_auth -> user.product_labels), e.g.
 * Group Name = "Main Service", Name = "Service". Rewrites those labels and
 * the help texts around them in the loaded translations, so every form that
 * uses the keys below follows (products, quick add, sale / purchase /
 * quotation pickers, lists, filters). Empty = the standard texts.
 */
const baseMessages = new Map(); // locale -> messages as fetched (before renaming)
let productLabels = { group: null, name: null };
const LABEL_KEYS = ['GroupName', 'Group_Name', 'GroupName_Help', 'Name_product', 'Name_Exists_In_Group', 'Name_In_Group_Help', 'Name_Pick_Or_Type', 'Name_Choose_Group_First', 'Create_new_group'];
// The plain words inside the help texts, per language.
const LABEL_WORDS = { en: { group: 'group', name: 'name' }, es: { group: 'grupo', name: 'nombre' }, fr: { group: 'groupe', name: 'nom' } };

const escRe = s => String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

// One pass over the text (so a new label is never renamed again): the
// language's "Group Name" phrase -> the group label as is; the plain words
// "group(s)" / "name(s)" -> the labels in lower case ("Group" at the start
// of a sentence keeps its capital, a plural keeps its "s").
function relabel(key, text, base, locale) {
    const { group, name } = productLabels;
    if (typeof text !== 'string') return text;
    if (key === 'GroupName' || key === 'Group_Name') return group || text;
    if (key === 'Name_product') return name || text;
    const words = LABEL_WORDS[locale] || {};
    const parts = [];
    if (group) parts.push(`(${escRe(base.GroupName || 'Group Name')})`);
    else parts.push('(\u0000)'); // keeps the group numbering stable
    parts.push(group && words.group ? `\\b(${escRe(words.group)})(s|es)?\\b` : '(\u0000)(\u0000)?');
    parts.push(name && words.name ? `\\b(${escRe(words.name)})(s|es)?\\b` : '(\u0000)(\u0000)?');
    const word = (w, label, plural) => {
        const lower = label.toLowerCase();
        return (w[0] === w[0].toUpperCase() ? lower.charAt(0).toUpperCase() + lower.slice(1) : lower) + (plural ? 's' : '');
    };
    let out = text.replace(new RegExp(parts.join('|'), 'gi'), (m, phrase, gw, gp, nw, np) => {
        if (phrase) return group;
        if (gw) return word(gw, group, gp);
        if (nw) return word(nw, name, np);
        return m;
    });
    // English article: "a area" -> "an area".
    if (locale === 'en') {
        for (const label of [group, name]) {
            if (label && /^[aeiou]/i.test(label)) out = out.replace(new RegExp(`\\b([Aa]) (${escRe(label)})`, 'gi'), '$1n $2');
        }
    }
    return out;
}

function applyProductLabels(locale) {
    const base = baseMessages.get(locale);
    if (!base) return;
    const patch = {};
    for (const k of LABEL_KEYS) {
        if (base[k] !== undefined) patch[k] = relabel(k, base[k], base, locale);
    }
    i18n.global.mergeLocaleMessage(locale, patch);
}

/** Set the acting company's labels ({group, name}) and rewrite every loaded language. */
export function setProductLabels(labels) {
    productLabels = {
        group: (labels && String(labels.group || '').trim()) || null,
        name: (labels && String(labels.name || '').trim()) || null,
    };
    for (const locale of baseMessages.keys()) applyProductLabels(locale);
}

/** The acting company's label for "Group Name" / "Name" (for texts built in code). */
export function productLabel(which) {
    return productLabels[which] || null;
}

export function applyDirection(locale) {
    const dir = isRtlLocale(locale) ? 'rtl' : 'ltr';
    document.documentElement.setAttribute('dir', dir);
    document.documentElement.setAttribute('lang', locale);
}

/**
 * A data text (e.g. an expense category name) in the active language, read
 * straight from the loaded translations map: the text itself is the key,
 * so no vue-i18n key-path / message parsing (names may hold dots, @, |...).
 * Undefined when there's no translation.
 */
export function dataTranslation(text) {
    if (!text) return undefined;
    const messages = i18n.global.getLocaleMessage(i18n.global.locale.value) || {};
    const v = messages[text];
    return typeof v === 'string' && v.trim() ? v : undefined;
}

/**
 * A menu / UI text in the active language, looser than dataTranslation():
 * the text as the key, then with underscores ("Service Jobs" -> Service_Jobs),
 * then the same two ignoring upper / lower case (keys are case-sensitive and
 * many are stored lower case: "Deposit" -> deposit, "SMS Templates" ->
 * sms_templates). Undefined when there's no translation.
 */
let ciIndex = { messages: null, map: null };
export function looseTranslation(text) {
    const s = String(text ?? '').trim();
    if (!s) return undefined;
    const under = s.replace(/\s+/g, '_');
    const direct = dataTranslation(s) ?? dataTranslation(under);
    if (direct !== undefined) return direct;
    const messages = i18n.global.getLocaleMessage(i18n.global.locale.value) || {};
    if (ciIndex.messages !== messages) {
        const map = new Map();
        for (const [k, v] of Object.entries(messages)) {
            if (typeof v === 'string' && v.trim() && !map.has(k.toLowerCase())) map.set(k.toLowerCase(), v);
        }
        ciIndex = { messages, map };
    }
    return ciIndex.map.get(s.toLowerCase()) ?? ciIndex.map.get(under.toLowerCase());
}

/** Translate with a human-readable fallback when a key is missing. */
export function t(key, fallback) {
    const out = i18n.global.t(key);
    return out === key && fallback ? fallback : out;
}
