import { defineStore } from 'pinia';

// Offline / first-paint cache only -- the source of truth is the company's
// settings.datatable_settings (System Settings -> DataTable), served to every
// user via get_user_auth and applied with applyServer().
const KEY = 'next_datatable_prefs';

// Pure Ant Design look — "Reset to default" restores exactly this.
export const DATATABLE_DEFAULTS = {
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
const DEFAULTS = DATATABLE_DEFAULTS;

export const FONT_STACKS = {
    default: null, // inherit Ant Design's stack
    mono: "ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, 'Liberation Mono', monospace",
    system: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif",
    serif: "Georgia, 'Times New Roman', Times, serif",
};

function pick(obj) {
    const out = {};
    for (const k of Object.keys(DEFAULTS)) out[k] = obj && obj[k] !== undefined ? obj[k] : DEFAULTS[k];
    return out;
}

function load() {
    try {
        const raw = window.localStorage.getItem(KEY);
        return pick(raw ? JSON.parse(raw) : null);
    } catch (e) {
        return { ...DEFAULTS };
    }
}

/**
 * The same format on EVERY table, including raw <a-table>s that don't go
 * through components/DataTable.vue: the prefs are mirrored onto <html> as CSS
 * vars + classes, and assets/theme.css styles every .ant-table from them
 * (DataTable.vue's own table is excluded there -- it applies them itself).
 */
function applyGlobal(p) {
    try {
        const root = document.documentElement;
        const vars = {
            '--gdt-font': FONT_STACKS[p.fontFamily] || 'inherit',
            '--gdt-font-size': `${p.fontSize}px`,
            '--gdt-th-weight': p.headerWeight || '600',
            '--gdt-th-size': `${p.headerFontSize || 14}px`,
            '--gdt-th-transform': p.headerUppercase ? 'uppercase' : 'none',
            '--gdt-th-bg': p.headerBg || '',
            '--gdt-th-color': p.headerColor || '',
        };
        Object.entries(vars).forEach(([k, v]) => (v ? root.style.setProperty(k, v) : root.style.removeProperty(k)));
        root.classList.toggle('gdt-striped', !!p.striped);
        root.classList.toggle('gdt-bordered', !!p.bordered);
        root.classList.toggle('gdt-th-bg', !!p.headerBg);
        root.classList.toggle('gdt-th-color', !!p.headerColor);
        ['small', 'middle', 'large'].forEach(d => root.classList.toggle(`gdt-density-${d}`, p.density === d));
    } catch (e) { /* no DOM (SSR/tests) */ }
}

/** Company-wide display preferences for every table (fonts, density, which
 *  toolbar components show). */
export const useDatatableStore = defineStore('datatable', {
    state: () => {
        const s = load();
        applyGlobal(s);
        return s;
    },
    getters: {
        // CSS font-family for tbody cells, or null to inherit.
        fontStack: s => FONT_STACKS[s.fontFamily] ?? null,
        // Plain snapshot of the prefs -- what System Settings saves.
        snapshot: s => pick(s),
    },
    actions: {
        // Live edit (System Settings -> DataTable preview); persisted to the
        // company when the settings page is saved.
        set(patch) {
            Object.assign(this, patch);
            this.persistLocal();
        },
        reset() {
            this.set({ ...DEFAULTS });
        },
        // The company's saved format (get_user_auth -> datatable_settings);
        // null/absent = built-in defaults.
        applyServer(prefs) {
            Object.assign(this, pick(prefs && typeof prefs === 'object' ? prefs : null));
            this.persistLocal();
        },
        persistLocal() {
            const out = pick(this);
            applyGlobal(out);
            try {
                window.localStorage.setItem(KEY, JSON.stringify(out));
            } catch (e) { /* ignore */ }
        },
    },
});
