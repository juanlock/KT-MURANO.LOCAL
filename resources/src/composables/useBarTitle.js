import { watch, onUnmounted, isRef, unref } from 'vue';
import { useRoute } from 'vue-router';
import { useUiStore } from '../stores/ui';
import { useMenuStore } from '../stores/menu';

/**
 * The page's title in the top bar, in bold next to the company name, instead of a header of its own (user's rule,
 * 2026-11-21: "todos los formularios de este módulo, el título arriba para ganar espacio visual"). The company's own
 * name for that menu item wins (menu_positions.label, e.g. Hordex's "Facturas de Hordex"); else `fallback`
 * (a string, a ref or a getter). Cleared when the page closes.
 */
export function useBarTitle(fallback) {
    const ui = useUiStore();
    const menu = useMenuStore();
    const route = useRoute();
    const own = () => {
        const key = '/app' + route.path;
        const row = (menu.positions || []).find(p => p.key === key && p.label);
        return row ? row.label : null;
    };
    const value = () => own() || (typeof fallback === 'function' ? fallback() : isRef(fallback) ? unref(fallback) : fallback) || '';
    watch([value, () => route.path], () => { ui.barTitle = value(); }, { immediate: true });
    onUnmounted(() => { ui.barTitle = ''; });
}
