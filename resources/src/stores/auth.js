import { defineStore } from 'pinia';
import http from '../lib/http';
import { isModuleEnabled } from '../config/modules';
import { loadLocale } from '../i18n';
import { useUiStore } from './ui';
import { resolveImageUrl } from '../lib/imagePath';
import { useDatatableStore } from './datatable';

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
        // The backend now stores the avatar's full relative path (e.g.
        // images/company_2/avatar/xxx.jpg), not a bare filename under a
        // shared /images/avatar/ folder -- use it as-is (prefixed with a
        // leading slash), falling back to the shipped default when the
        // user has none.
        avatarUrl: (s) => {
            const avatar = s.user && s.user.avatar;
            if (!avatar) return '/images/avatar/no_avatar.png';
            return avatar.startsWith('/') ? avatar : `/${avatar}`;
        },
        // Per-user switch from the DB — hides the language picker entirely.
        showLanguage: s => !s.user || !!s.user.show_language,
        // System Settings → Appearance → "Show customize button". Default true
        // when the setting is absent, as legacy did.
        customizeButtonVisible: s => !s.user || s.user.customize_button_visible !== false,

        // ---- Branding (settings) — the same fields the legacy layout reads ----
        // Company name, blank when the admin chose to hide the site name.
        companyName: s => (s.user && !s.user.hide_site_name ? (s.user.company || 'KcolTech - Murano') : ''),
        // Uploaded logo (old bare filename under shared /images/, or the
        // new company-scoped relative path), or null to fall back to an initial.
        logoUrl: s => resolveImageUrl(s.user && s.user.logo),
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
        developedBy: s => (s.user && s.user.developed_by) || 'KcolTech - Murano',
        // kt_logs track-version ("MM.mm"), bumps on every logged request.
        // Same for every user (all companies + SuperAdmin) -- not company-scoped.
        ktLogVersion: s => (s.user && s.user.kt_log_version) || '',
        // Admin module toggles (System Settings → Modules). Null map or a
        // missing key = module enabled, so fresh installs show everything.
        moduleEnabled: s => key => isModuleEnabled(s.user && s.user.module_flags, key),
        // System Settings → Defaults → Customers. Missing flag = on, so older
        // cached payloads keep showing everything.
        customerLoyaltyEnabled: s => !s.user || s.user.customer_loyalty_enabled !== false,
        customerCreditLimitEnabled: s => !s.user || s.user.customer_credit_limit_enabled !== false,
        customerOpeningBalanceEnabled: s => !s.user || s.user.customer_opening_balance_enabled !== false,
        // POS Settings -> "Show POS button in header" (missing = on).
        posButtonEnabled: s => !s.user || s.user.show_pos_button !== false,
        // DataTable "Customize Columns": per-table admin column setup for this
        // company ({tableKey: [{key, title, width, hidden}]}), and who may edit it.
        datatableColumns: s => (s.user && s.user.datatable_columns) || {},
        canCustomizeColumns: s => !!(s.user && (s.user.type === 'SuperAdmin' || s.permissions.includes('datatable_customize'))),
        // "Customize Filters": same idea, for the filter row above a list
        // ({tableKey: [{key, hidden}]}). Reuses the same permission/getter
        // above -- an admin who may customize columns may customize filters.
        datatableFilters: s => (s.user && s.user.datatable_filters) || {},
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

                // The SPA's locale otherwise lives entirely in localStorage
                // ('language', written on every boot including the very
                // first one), so a company's default_language never actually
                // reached the UI. Apply it here, but only when THIS user
                // hasn't made their own explicit choice (stores/ui.js stamps
                // 'language_explicit' + the acting user's id when picked
                // from the switcher) -- matches SetLocale.php's server-side
                // order: explicit choice first, company default as the
                // fallback. Checking the stamped user id (not just the bare
                // flag) matters on a shared/test browser: one admin's
                // manual pick must not silently stick for every other
                // company's admin who logs in afterwards on the same
                // device -- each of THEM still gets their own company
                // default until they personally pick something too.
                try {
                    const explicit = window.localStorage.getItem('language_explicit') === '1'
                        && window.localStorage.getItem('language_explicit_user_id') === String(this.user && this.user.id);
                    const serverLocale = this.user && this.user.default_language;
                    if (!explicit && serverLocale) {
                        const ui = useUiStore();
                        if (ui.locale !== serverLocale) {
                            ui.locale = serverLocale;
                            await loadLocale(serverLocale);
                        }
                    }
                } catch (e) { /* best effort -- never block auth on locale sync */ }
                // Same explicit-choice-wins-over-company-default pattern as
                // locale above, for System Settings -> Appearance -> Primary
                // Color (Ant Design's colorPrimary token, App.vue).
                try {
                    const ui = useUiStore();
                    ui.applyServerPrimaryColor(this.user && this.user.primary_color, this.user && this.user.id);
                } catch (e) { /* best effort -- never block auth on this */ }
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
                // System Settings -> DataTable is company-wide: every user's
                // tables follow the company's saved format (null = defaults).
                if (this.user) {
                    try { useDatatableStore().applyServer(this.user.datatable_settings); } catch (e) { /* best effort */ }
                }
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
