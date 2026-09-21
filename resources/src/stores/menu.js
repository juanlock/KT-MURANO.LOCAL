import { defineStore } from 'pinia';
import { MENU } from '../config/menu';
import { applyMenuOrder } from '../lib/menuOrder';
import { useAuthStore } from './auth';

/**
 * The navigation actually rendered by the sidebars: the default MENU arranged
 * per the admin-saved order (Settings → Sidebar Menu). The saved order arrives
 * with get_user_auth (auth.user.sidebar_menu_order), so this recomputes both at
 * boot and live when the manager saves a new arrangement.
 */
export const useMenuStore = defineStore('menu', {
    getters: {
        menu() {
            const auth = useAuthStore();
            const order = auth.user && auth.user.sidebar_menu_order;
            let arranged = MENU;
            if (Array.isArray(order) && order.length) {
                try {
                    arranged = applyMenuOrder(order);
                } catch (e) {
                    // A malformed saved order must never take the navigation down.
                    arranged = MENU;
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
