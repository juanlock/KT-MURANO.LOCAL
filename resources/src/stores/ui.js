import { defineStore } from 'pinia';
import { loadLocale, storedLocale, isRtlLocale } from '../i18n';
import http from '../lib/http';

const DARK_KEY = 'next_dark_mode';
const THEME_KEY = 'next_theme_mode';
const COLOR_KEY = 'next_primary_color';
const RTL_KEY = 'next_rtl';
const SIDEBAR_KEY = 'next_sidebar_compact';
const LAYOUT_KEY = 'next_sidebar_layout';
const TEXT_MODE_KEY = 'next_sidebar_text_mode';
const TEXT_MODES = ['auto', 'white', 'black'];
const FONT_SIZE_KEY = 'next_sidebar_font_size';
const BOLD_KEY = 'next_sidebar_bold';
const DEFAULT_FONT_SIZE = 14; // matches ant-design-vue Menu's own default fontSize
const MIN_FONT_SIZE = 11;
const MAX_FONT_SIZE = 20;
// Microsoft/SharePoint "Blue" theme primary -- https://learn.microsoft.com/en-us/sharepoint/dev/design/themes-colors
const DEFAULT_COLOR = '#0078d4';
const SIDEBAR_LAYOUTS = ['default', 'large', 'flat', 'dark'];

function stored(key, fallback) {
    try {
        const v = window.localStorage.getItem(key);
        return v === null ? fallback : v;
    } catch (e) {
        return fallback;
    }
}
function save(key, value) {
    try {
        window.localStorage.setItem(key, value);
    } catch (e) { /* ignore */ }
}

const THEME_MODES = ['light', 'dark', 'auto'];
const darkMql = typeof window !== 'undefined' && window.matchMedia
    ? window.matchMedia('(prefers-color-scheme: dark)')
    : null;
let themeWatcherAttached = false;

// Falls back to the pre-existing boolean key so upgrades keep their choice.
function initialThemeMode() {
    const m = stored(THEME_KEY, null);
    if (THEME_MODES.includes(m)) return m;
    return stored(DARK_KEY, '0') === '1' ? 'dark' : 'light';
}
function modeIsDark(mode) {
    if (mode === 'auto') return !!(darkMql && darkMql.matches);
    return mode === 'dark';
}

/** App chrome preferences: dark mode, primary colour and RTL (this app's own
 *  keys) plus locale (shared with the legacy admin via localStorage
 *  'language'). All are the settings the legacy customizer panel exposed. */
export const useUiStore = defineStore('ui', {
    state: () => ({
        // 'light' | 'dark' | 'auto' — `dark` below is the resolved boolean the
        // rest of the app keeps reading (App.vue theme algorithm, topbar, …).
        themeMode: initialThemeMode(),
        dark: modeIsDark(initialThemeMode()),
        primaryColor: stored(COLOR_KEY, DEFAULT_COLOR),
        // Last company-level Appearance Settings color seen from the server
        // (null until auth loads, or if the company hasn't set one). Lets
        // resetPrimaryColor() go back to "follow the company" instead of
        // just the app's hardcoded brand color.
        companyPrimaryColor: null,
        // Null → follow the locale's natural direction; '1'/'0' → manual override.
        rtl: stored(RTL_KEY, null),
        // Which sidebar to render: 'default' (Ant inline accordion) or 'large'
        // (the legacy two-pane rail + submenu panel).
        sidebarLayout: SIDEBAR_LAYOUTS.includes(stored(LAYOUT_KEY, 'default'))
            ? stored(LAYOUT_KEY, 'default') : 'default',
        // Dark Menu's text/icon colour: 'auto' picks white or black for
        // contrast against whatever Primary Color is chosen (see
        // colorUtils.isLightColor()); 'white'/'black' are a manual override
        // for the rare case auto doesn't read right on a particular colour.
        sidebarTextMode: TEXT_MODES.includes(stored(TEXT_MODE_KEY, 'auto'))
            ? stored(TEXT_MODE_KEY, 'auto') : 'auto',
        // Sidebar menu row text size (px) and bold toggle -- applies to the
        // Ant-menu-based sidebar (Default/Dark Menu layouts).
        sidebarFontSize: (() => {
            const n = Number(stored(FONT_SIZE_KEY, DEFAULT_FONT_SIZE));
            return Number.isFinite(n) && n >= MIN_FONT_SIZE && n <= MAX_FONT_SIZE ? n : DEFAULT_FONT_SIZE;
        })(),
        sidebarBold: stored(BOLD_KEY, '0') === '1',
        // Hamburger collapse — an icon rail for the default layout, and hides
        // the submenu panel for the large one.
        sidebarCollapsed: stored(SIDEBAR_KEY, '0') === '1',
        // Sidebar "filter menus" search box -- typed text only, never
        // persisted (a fresh session starts with the full menu showing).
        menuSearchQuery: '',
        locale: storedLocale(),
        // Theme customizer drawer -- toggled from the topbar's Customize
        // button; never persisted, always starts closed.
        customizerOpen: false,
    }),
    getters: {
        // A manual RTL choice wins; otherwise the locale decides (ar → rtl).
        direction: s => {
            if (s.rtl === '1') return 'rtl';
            if (s.rtl === '0') return 'ltr';
            return isRtlLocale(s.locale) ? 'rtl' : 'ltr';
        },
    },
    actions: {
        setThemeMode(mode) {
            this.themeMode = THEME_MODES.includes(mode) ? mode : 'light';
            save(THEME_KEY, this.themeMode);
            this.dark = modeIsDark(this.themeMode);
            // Legacy boolean key stays in sync for anything still reading it.
            save(DARK_KEY, this.dark ? '1' : '0');
        },
        // Follow OS theme changes live while in auto mode. Idempotent.
        initTheme() {
            if (!darkMql || themeWatcherAttached) return;
            themeWatcherAttached = true;
            const onChange = () => {
                if (this.themeMode === 'auto') this.dark = modeIsDark('auto');
            };
            if (darkMql.addEventListener) darkMql.addEventListener('change', onChange);
            else if (darkMql.addListener) darkMql.addListener(onChange);
        },
        // Quick light/dark flip (topbar bulb); leaves auto mode explicitly.
        toggleDark() {
            this.setThemeMode(this.dark ? 'light' : 'dark');
        },
        /**
         * A deliberate pick from the Customizer. Stamped with the acting
         * user's id (same reasoning as setLocale()'s 'language_explicit'):
         * without it, one admin's manual pick in a shared/test browser would
         * silently override every other company's admin who later logs in
         * on that same device.
         */
        setPrimaryColor(color, userId) {
            this.primaryColor = color || DEFAULT_COLOR;
            save(COLOR_KEY, this.primaryColor);
            try {
                window.localStorage.setItem('primary_color_explicit', '1');
                window.localStorage.setItem('primary_color_explicit_user_id', String(userId ?? ''));
            } catch (e) { /* ignore */ }
        },
        // "Reset" means stop personally overriding it -- go back to
        // following the company's own Appearance Settings color (or the
        // app's built-in default if the company hasn't set one).
        resetPrimaryColor() {
            try {
                window.localStorage.removeItem('primary_color_explicit');
                window.localStorage.removeItem('primary_color_explicit_user_id');
            } catch (e) { /* ignore */ }
            this.primaryColor = this.companyPrimaryColor || DEFAULT_COLOR;
            save(COLOR_KEY, this.primaryColor);
        },
        /**
         * Applies the company's Appearance Settings color WITHOUT stamping
         * it as this user's own explicit choice -- mirrors
         * applyServerLocale()/the load() locale-sync block below. Called
         * after auth loads; only takes effect when this user hasn't
         * personally picked a color via the Customizer.
         */
        applyServerPrimaryColor(color, userId) {
            this.companyPrimaryColor = color || null;
            try {
                const explicit = window.localStorage.getItem('primary_color_explicit') === '1'
                    && window.localStorage.getItem('primary_color_explicit_user_id') === String(userId ?? '');
                if (!explicit) {
                    this.primaryColor = color || DEFAULT_COLOR;
                    save(COLOR_KEY, this.primaryColor);
                }
            } catch (e) { /* best effort -- never block auth on this */ }
        },
        setRtl(on) {
            this.rtl = on ? '1' : '0';
            save(RTL_KEY, this.rtl);
        },
        setSidebarCollapsed(on) {
            this.sidebarCollapsed = !!on;
            save(SIDEBAR_KEY, this.sidebarCollapsed ? '1' : '0');
        },
        toggleSidebar() {
            this.setSidebarCollapsed(!this.sidebarCollapsed);
        },
        setSidebarLayout(layout) {
            this.sidebarLayout = SIDEBAR_LAYOUTS.includes(layout) ? layout : 'default';
            save(LAYOUT_KEY, this.sidebarLayout);
        },
        setSidebarTextMode(mode) {
            this.sidebarTextMode = TEXT_MODES.includes(mode) ? mode : 'auto';
            save(TEXT_MODE_KEY, this.sidebarTextMode);
        },
        setSidebarFontSize(size) {
            const n = Number(size);
            this.sidebarFontSize = Number.isFinite(n)
                ? Math.min(MAX_FONT_SIZE, Math.max(MIN_FONT_SIZE, n))
                : DEFAULT_FONT_SIZE;
            save(FONT_SIZE_KEY, String(this.sidebarFontSize));
        },
        setSidebarBold(on) {
            this.sidebarBold = !!on;
            save(BOLD_KEY, this.sidebarBold ? '1' : '0');
        },
        toggleCustomizer() {
            this.customizerOpen = !this.customizerOpen;
        },
        /**
         * Applies a locale WITHOUT stamping it as the user's own explicit
         * choice -- for automatic syncs (e.g. after saving System Settings'
         * Default Language for the acting company) as opposed to a deliberate
         * pick from the language switcher. Using setLocale() for this stamped
         * 'language_explicit', which then permanently overrode the correct
         * per-company default on every later login -- e.g. a SuperAdmin
         * saving a Spanish-default company's settings would get stuck seeing
         * the whole app in Spanish even after switching back to an
         * English-default company, until they happened to pick a language
         * manually again.
         */
        async applyServerLocale(locale) {
            this.locale = locale;
            await loadLocale(locale);
        },
        async setLocale(locale, userId) {
            this.locale = locale;
            // Remembers this was a deliberate pick, not the boot-time
            // fallback -- see stores/auth.js: a device with no explicit
            // choice (for the CURRENTLY logged-in user) adopts that user's
            // company default_language instead of always sticking at 'en'.
            // Stamped with the acting user's id, not just a bare flag --
            // otherwise one admin's manual pick in a shared/test browser
            // would silently override every other company's admin who
            // later logs in on that same browser.
            try {
                window.localStorage.setItem('language_explicit', '1');
                window.localStorage.setItem('language_explicit_user_id', String(userId ?? ''));
            } catch (e) { /* ignore */ }
            await loadLocale(locale);
            // Tell the server too. The language lived only in localStorage, so
            // anything Blade renders — PDFs, and the logged-out /login page —
            // had no way to know about it. /api/sync-locale parks the choice in
            // a 1-year cookie that SetLocale reads. Best effort: a failure here
            // must not undo the language switch the user just made.
            try {
                await http.post('sync-locale', { locale });
            } catch (e) { /* offline, or signed out — the UI is already switched */ }
        },
    },
});

export { DEFAULT_COLOR, MIN_FONT_SIZE, MAX_FONT_SIZE };
