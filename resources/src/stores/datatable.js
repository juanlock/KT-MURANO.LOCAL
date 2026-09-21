import { defineStore } from 'pinia';

const KEY = 'next_datatable_prefs';

// Pure Ant Design look — "Reset to default" restores exactly this.
const DEFAULTS = {
    // 'default' inherits Ant's font; the rest map to stacks below.
    fontFamily: 'default',
    fontSize: 14,
    // Row density — a-table `size`.
    density: 'middle',
    bordered: false,
    striped: false,
    // Sortable columns: when on, every column gets a sorter — columns the
    // backend supports keep server-side sorting, the rest sort the loaded
    // page client-side. When off, no column shows sort arrows.
    autoSort: true,
    // Toolbar components.
    showSearch: true,
    showColumns: true,
    showRefresh: true,
    // Header (thead) — empty color = keep the theme's default.
    headerBg: '',
    headerColor: '',
    headerWeight: '600',
    headerFontSize: 14,
    headerUppercase: false,
};

export const FONT_STACKS = {
    default: null, // inherit Ant Design's stack
    mono: "ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, 'Liberation Mono', monospace",
    system: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif",
    serif: "Georgia, 'Times New Roman', Times, serif",
};

function load() {
    try {
        const raw = window.localStorage.getItem(KEY);
        if (!raw) return { ...DEFAULTS };
        const saved = JSON.parse(raw);
        return { ...DEFAULTS, ...(saved && typeof saved === 'object' ? saved : {}) };
    } catch (e) {
        return { ...DEFAULTS };
    }
}

/** Per-device display preferences for every DataTable list (fonts, density,
 *  which toolbar components show). Persisted to localStorage. */
export const useDatatableStore = defineStore('datatable', {
    state: () => load(),
    getters: {
        // CSS font-family for tbody cells, or null to inherit.
        fontStack: s => FONT_STACKS[s.fontFamily] ?? null,
    },
    actions: {
        set(patch) {
            Object.assign(this, patch);
            try {
                const out = {};
                for (const k of Object.keys(DEFAULTS)) out[k] = this[k];
                window.localStorage.setItem(KEY, JSON.stringify(out));
            } catch (e) { /* ignore */ }
        },
        reset() {
            this.set({ ...DEFAULTS });
        },
    },
});
