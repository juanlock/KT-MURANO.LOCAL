/**
 * Options-API mixin injected into the ported POS page. Provides every global
 * the legacy script assumes: $bvModal/$bvToast, the Fire bus, Vue 2's
 * $set/$delete, a minimal $store facade over our Pinia auth/ui stores, and
 * neutral `errors`/`valid` fallbacks for template spots whose Vue-2-only
 * slot-scope had to be stripped.
 */
import { bvModal, bvToast, swal } from './bv';
import { useAuthStore } from '../stores/auth';
import { loadLocale, applyDirection } from '../i18n';

export default {
    data() {
        return {
            // Neutral validation state for stripped slot-scope references.
            errors: [],
            valid: null,
        };
    },
    computed: {
        currentUser() {
            return useAuthStore().user || {};
        },
        currentUserPermissions() {
            return useAuthStore().permissions || [];
        },
        show_language() {
            return true;
        },
        $store() {
            // Legacy touches $store via dispatch + priceFormat helpers that
            // read getters with safe fallbacks.
            return {
                getters: {},
                dispatch: (action, payload) => {
                    if (action === 'logout') {
                        // The POS used to jump straight to /login without ending
                        // the Laravel session, so the guest middleware bounced it
                        // back to the dashboard. Kill the session first, then go.
                        try { localStorage.removeItem('stocky_auth_cache_v1'); } catch (e) {}
                        const done = () => window.location.replace('/login');
                        fetch('/logout', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        }).then(done, done);
                    } else if (action === 'setLanguage') {
                        localStorage.setItem('language', payload);
                        loadLocale(payload).then(() => applyDirection(payload)).catch(() => {});
                    }
                },
            };
        },
    },
    created() {
        this.$bvModal = bvModal;
        this.$bvToast = bvToast;
        this.$swal = swal;
        // Vue 3 reactivity makes plain assignment reactive — Vue 2's escape
        // hatches reduce to these.
        this.$set = (target, key, value) => { target[key] = value; return value; };
        this.$delete = (target, key) => { delete target[key]; };
    },
};
