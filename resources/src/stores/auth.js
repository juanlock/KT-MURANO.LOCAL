import { defineStore } from 'pinia';
import http from '../lib/http';
import { isModuleEnabled } from '../config/modules';

/**
 * Mirrors the legacy Vuex auth module: GET /api/get_user_auth returns
 * { permissions, user, notifs }. Loaded once by the router guard.
 */
const AUTH_CACHE_KEY = 'stocky_auth_cache_v1';

function readAuthCache() {
    try {
        const raw = window.localStorage.getItem(AUTH_CACHE_KEY);
        return raw ? JSON.parse(raw) : null;
    } catch (e) {
        return null;
    }
}
export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        permissions: [],
        // Quantity-alert count behind the topbar bell (legacy notifs_alert).
        notifs: 0,
        loaded: false,
        failed: false,
    }),
    getters: {
        currency: s => (s.user && s.user.currency) || '$',
        // Multi-Currency module toggle (System Settings → Features).
        multiCurrencyEnabled: s => !!(s.user && s.user.enable_multi_currency),
        // Base currency id (settings.currency_id) — document pickers default to it.
        defaultCurrencyId: s => (s.user && s.user.default_currency_id) || null,
        username: s => (s.user && s.user.username) || '',
        // Legacy resolves the avatar under /images/avatar/ and falls back to
        // the shipped default when the user has none.
        avatarUrl: s => `/images/avatar/${(s.user && s.user.avatar) || 'avatar-default.jpg'}`,
        // Per-user switch from the DB — hides the language picker entirely.
        showLanguage: s => !s.user || !!s.user.show_language,

        // ---- Branding (settings) — the same fields the legacy layout reads ----
        // Company name, blank when the admin chose to hide the site name.
        companyName: s => (s.user && !s.user.hide_site_name ? (s.user.company || 'Stocky') : ''),
        // Uploaded logo under /images/, or null to fall back to an initial.
        logoUrl: s => (s.user && s.user.logo ? `/images/${s.user.logo}` : null),
        // Admin toggle: hide the logo/initial in the sidebar brand entirely.
        showSidebarLogo: s => !s.user || s.user.sidebar_show_logo !== false,
        // Admin-configured sidebar logo size (px), defaulting to the historical 32x32.
        sidebarLogoStyle: s => ({
            width: ((s.user && s.user.sidebar_logo_width) || 32) + 'px',
            height: ((s.user && s.user.sidebar_logo_height) || 32) + 'px',
        }),
        // First letter of the company, the legacy placeholder when there's no logo.
        brandInitial: s => ((s.user && s.user.company && s.user.company[0]) || 'S').toUpperCase(),
        // Custom footer line and the "developed by" credit.
        footerText: s => (s.user && s.user.footer) || '',
        developedBy: s => (s.user && s.user.developed_by) || 'Stocky',
        // Admin module toggles (System Settings → Modules). Null map or a
        // missing key = module enabled, so fresh installs show everything.
        moduleEnabled: s => key => isModuleEnabled(s.user && s.user.module_flags, key),
        // Global export defaults (System Settings → Export). Missing keys keep
        // the historical behavior: full data, totals on, landscape, plain names.
        exportSettings: s => ({
            scope: 'all',
            totals: true,
            pdf_orientation: 'landscape',
            filename_date: false,
            pdf_meta: false,
            ...((s.user && s.user.export_settings) || {}),
        }),
    },
    actions: {
        async load() {
            if (this.loaded) return;
            try {
                const data = await http.get('get_user_auth');
                this.user = data.user || null;
                this.permissions = Array.isArray(data.permissions) ? data.permissions : [];
                this.notifs = Number(data.notifs) || 0;
                this.failed = false;
                // Snapshot for offline boots: a POS refresh with no network must
                // keep the real user + permissions instead of failing open.
                try {
                    window.localStorage.setItem(AUTH_CACHE_KEY, JSON.stringify({
                        user: this.user,
                        permissions: this.permissions,
                    }));
                } catch (e) { /* storage unavailable — offline fallback skipped */ }
            } catch (e) {
                // Network failure (offline reload): hydrate the last known
                // user/permissions so the app behaves normally. 401s already
                // redirected to /login inside http, so a cached snapshot here
                // belongs to the still-authenticated session cookie holder.
                const cached = readAuthCache();
                if (cached && cached.user) {
                    this.user = cached.user;
                    this.permissions = Array.isArray(cached.permissions) ? cached.permissions : [];
                    this.failed = false;
                } else {
                    // Stay usable in design-preview mode when the API is
                    // unreachable and nothing is cached.
                    this.failed = true;
                }
            } finally {
                this.loaded = true;
            }
        },
        // Force a re-fetch (load() is once-only) — used after saving settings
        // that feed the chrome (sidebar brand, footer) so it updates in place.
        async reload() {
            this.loaded = false;
            await this.load();
        },
        can(permission) {
            // Fail-open when auth could not be loaded (demo mode) so the UI
            // remains explorable; real requests still get rejected server-side.
            if (this.failed) return true;
            return this.permissions.includes(permission);
        },
    },
});
