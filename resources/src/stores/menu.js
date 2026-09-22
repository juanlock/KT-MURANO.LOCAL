import { defineStore } from 'pinia';
import { MENU } from '../config/menu';
import { applyMenuOrder } from '../lib/menuOrder';
import { buildTopLevelMenu } from '../lib/menuTree';
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

            if (Array.isArray(order) && order.length) {
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
            const dropDisabled = entries => entries
                .filter(entry => auth.moduleEnabled(entry.key))
                .map(entry => (entry.children
                    ? { ...entry, children: dropDisabled(entry.children) }
                    : entry));
            return dropDisabled(arranged);
        },
    },
});
