import { defineStore } from 'pinia';
import http from '../lib/http';
import { useAuthStore } from './auth';
import { getActingCompanyId, setActingCompanyId } from '../lib/actingCompany';

/**
 * The SuperAdmin company switcher (AppTopbar.vue): which company every
 * page/form acts on. lib/actingCompany.js is the actual source of truth
 * (so lib/http.js can read it without importing this store); this wraps it
 * for Vue reactivity and owns fetching the company list.
 *
 * A no-op for everyone else -- companies stays empty and actingCompanyId
 * stays null, so http.js never attaches anything and every request falls
 * back to the user's own company_id server-side, same as before this existed.
 */
let loading = null; // the load() in flight (list + company picked)

export const useCompanyStore = defineStore('company', {
    state: () => ({
        companies: [],
        loaded: false,
        actingCompanyId: getActingCompanyId(),
    }),
    getters: {
        isSuperAdmin() {
            const auth = useAuthStore();
            return auth.user?.type === 'SuperAdmin';
        },
        activeCompanyName(state) {
            const c = state.companies.find(c => c.id === state.actingCompanyId);
            return c?.name || '';
        },
    },
    actions: {
        load() {
            // Not a SuperAdmin: drop any acting company left in this browser by an
            // earlier SuperAdmin session (e.g. AdminSystem/999) -- an Admin always
            // acts as their own company and never has a selector.
            if (!this.isSuperAdmin) {
                if (this.actingCompanyId) this.setCompany(null);
                return Promise.resolve();
            }
            // A second caller (the router guard of a global page while the topbar
            // is still loading) waits for the same whole load -- list AND the
            // company picked -- instead of returning early.
            if (loading) return loading;
            if (this.loaded) return Promise.resolve();
            this.loaded = true;
            loading = this.fetchCompanies().finally(() => { loading = null; });
            return loading;
        },
        async fetchCompanies() {
            try {
                this.companies = await http.get('get_all_company');
            } catch (e) {
                this.companies = [];
            }
            // get_all_company excludes soft-deleted companies (correct for
            // normal business dropdowns elsewhere) -- 999 is the SuperAdmin
            // sentinel "company" and menu_positions (at least) already has
            // real rows for it, so add it back in here, labeled for what it
            // actually is rather than its raw company name.
            if (!this.companies.some(c => c.id === 999)) {
                this.companies = [...this.companies, { id: 999, name: 'AdminSystem' }];
            }

            const auth = useAuthStore();
            const persistedValid = this.actingCompanyId && this.companies.some(c => c.id === this.actingCompanyId);
            if (!persistedValid) {
                this.setCompany(auth.user?.company_id || this.companies[0]?.id || null);
            }
        },
        setCompany(id) {
            this.actingCompanyId = id || null;
            setActingCompanyId(this.actingCompanyId);
        },
    },
});
