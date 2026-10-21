import { defineStore } from 'pinia';
import { MENU, resolveTarget } from '../config/menu';
import { isEntryEnabled, ALL_MODULES_ON } from '../config/modules';

/** A menu `to` ('/app/hospital/reports') -> its SPA path ('/hospital/reports'), or null. */
export const spaPathOf = to => {
    const r = resolveTarget(to);
    return r && r.type === 'next' ? r.path : null;
};
import { applyMenuOrder } from '../lib/menuOrder';
import { buildTopLevelMenu, hasNestedPositions } from '../lib/menuTree';
import { useAuthStore } from './auth';
import http from '../lib/http';

/**
 * The navigation actually rendered by the sidebars: the default MENU arranged
 * per the admin-saved order (Settings → Sidebar Menu). The saved order arrives
 * with get_user_auth (auth.user.sidebar_menu_order), so this recomputes both at
 * boot and live when the manager saves a new arrangement.
 */
export const useMenuStore = defineStore('menu', {
    state: () => ({
        // Admin-saved TOP-LEVEL layout from `menu_positions` (Billing-style
        // "Manage Menus", ported backend-only so far -- no editor screen
        // exists in Murano yet). Null until loadPositions() resolves; stays
        // an empty array if nothing has ever been saved there, in which case
        // the `menu` getter below behaves exactly as before this was added.
        positions: null,
    }),
    actions: {
        async loadPositions() {
            try {
                this.positions = await http.get('menu-positions');
            } catch (e) {
                this.positions = null; // fall back to the default+per-user layout
            }
        },
    },
    getters: {
        menu(state) {
            const auth = useAuthStore();
            const order = auth.user && auth.user.sidebar_menu_order;

            // Only the top level is DB-driven (see menuTree.js) -- each
            // entry's own `children` are whatever menu.js/applyMenuOrder
            // below decide, untouched by menu_positions.
            let arranged = Array.isArray(state.positions) && state.positions.length
                ? buildTopLevelMenu(state.positions)
                : MENU;

            // Once menu_positions covers anything below the top level (the
            // first full-tree save from the nested Manage Menus editor), it
            // alone decides structure/order at every level -- applyMenuOrder
            // only knows about static config/menu.js entries and would
            // silently drop any custom sub-item it doesn't recognize.
            if (!hasNestedPositions(state.positions) && Array.isArray(order) && order.length) {
                try {
                    arranged = applyMenuOrder(order, arranged);
                } catch (e) {
                    // A malformed saved order must never take the navigation down.
                }
            }
            // Drop entries whose module the admin switched off (Settings →
            // Modules). Applied at every level: connector entries (WooCommerce,
            // Shopify, Salla…) live nested under the Ecommerce Platforms /
            // Integrations sections but keep a `key` matching config/modules.js.
            // Entries without a key are never toggleable and always pass.
            // `requiresSetting` names an auth-store boolean getter (e.g.
            // customerLoyaltyEnabled) -- the entry is dropped while it's off.
            // Also by the page an item opens (Hospital Reports under Reports,
            // or an item moved into another group): isEntryEnabled.
            // The SuperAdmin sees every module of every company (2026-10-19).
            const flags = auth.user && (auth.user.type === 'SuperAdmin' ? ALL_MODULES_ON : auth.user.module_flags);
            // A company whose type has its own dashboard (hospital, school, real
            // estate -- 2026-10-20): no general Dashboard item.
            const ownDashboard = !!(auth.user && auth.user.own_dashboard);
            const dropDisabled = entries => entries
                .filter(entry => !(ownDashboard && (entry.key === 'dashboard' || entry.to === '/app/dashboard')))
                .filter(entry => isEntryEnabled(flags, entry, spaPathOf))
                .filter(entry => !entry.requiresSetting || auth[entry.requiresSetting] !== false)
                .map(entry => (entry.children
                    ? { ...entry, children: dropDisabled(entry.children) }
                    : entry));
            return dropDisabled(arranged);
        },
    },
});
