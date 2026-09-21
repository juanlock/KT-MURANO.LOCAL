import { defineStore } from 'pinia';
import { loadLocale, storedLocale, isRtlLocale } from '../i18n';
import http from '../lib/http';

const DARK_KEY = 'next_dark_mode';
const THEME_KEY = 'next_theme_mode';
const COLOR_KEY = 'next_primary_color';
const RTL_KEY = 'next_rtl';
const SIDEBAR_KEY = 'next_sidebar_compact';
const LAYOUT_KEY = 'next_sidebar_layout';
const DEFAULT_COLOR = '#6d28d9';
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
        // Null → follow the locale's natural direction; '1'/'0' → manual override.
        rtl: stored(RTL_KEY, null),
        // Which sidebar to render: 'default' (Ant inline accordion) or 'large'
        // (the legacy two-pane rail + submenu panel).
        sidebarLayout: SIDEBAR_LAYOUTS.includes(stored(LAYOUT_KEY, 'default'))
            ? stored(LAYOUT_KEY, 'default') : 'default',
        // Hamburger collapse — an icon rail for the default layout, and hides
        // the submenu panel for the large one.
        sidebarCollapsed: stored(SIDEBAR_KEY, '0') === '1',
        locale: storedLocale(),
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
        setPrimaryColor(color) {
            this.primaryColor = color || DEFAULT_COLOR;
            save(COLOR_KEY, this.primaryColor);
        },
        resetPrimaryColor() {
            this.setPrimaryColor(DEFAULT_COLOR);
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
        async setLocale(locale) {
            this.locale = locale;
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

export { DEFAULT_COLOR };
