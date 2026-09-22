/**
 * Merges an admin-saved TOP-LEVEL layout (Settings -> Manage Menus, backed by
 * the `menu_positions` table -- see MenuPositionController) onto config/menu.js.
 *
 * Ported from KT-Billing's menuTree.js, scoped down to just the top level:
 * only entries in MENU itself have a stable `key` field. Sub-items don't (see
 * menuOrder.js's nodeId(), which computes ids on the fly instead), so this
 * pass leaves each top-level entry's `children` exactly as menu.js defines
 * them -- reordering those is still menuOrder.js's per-user mechanism, layered
 * on top of whatever this function returns.
 */
import { MENU } from '../config/menu';

/** Serializes MENU's top level into the { key, sort_order } shape saved rows use. */
export function flattenTopLevelDefaults(menu = MENU) {
  return menu.map((entry, index) => ({ key: entry.key, sort_order: index }));
}

/**
 * Returns a new top-level array arranged per `positions` (the raw rows from
 * GET /api/menu-positions). Unknown/stale keys are ignored; any MENU entry
 * missing from `positions` (e.g. a brand-new module) keeps its default spot
 * at the end so nothing silently disappears. A row with no menu.js match and
 * `is_custom` true is a fully custom top-level entry (no submenu). A `hidden`
 * row -- built-in or custom -- is dropped.
 */
export function buildTopLevelMenu(positions, menu = MENU) {
  if (!Array.isArray(positions) || !positions.length) return menu;

  const configMap = new Map(menu.map(entry => [entry.key, entry]));
  const topLevelPositions = positions.filter(p => !p.parent_key);
  const seen = new Set();
  const rows = [];

  topLevelPositions.forEach(p => {
    if (configMap.has(p.key) || p.is_custom) {
      rows.push(p);
      seen.add(p.key);
    }
  });
  menu.forEach((entry, index) => {
    if (!seen.has(entry.key)) rows.push({ key: entry.key, sort_order: 1000 + index });
  });
  rows.sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0));

  return rows.reduce((acc, row) => {
    if (row.hidden) return acc;
    const node = resolveRow(row, configMap);
    if (node) acc.push(node);
    return acc;
  }, []);
}

function resolveRow(row, configMap) {
  const base = configMap.get(row.key);
  if (base) {
    // A renamed and/or re-iconed built-in module: keep its children/permissions.
    const overrides = {};
    if (row.label) { overrides.label = row.label; overrides.raw = true; }
    if (row.icon) overrides.icon = row.icon;
    return Object.keys(overrides).length ? { ...base, ...overrides } : base;
  }
  // A fully custom module created via Manage Menus -- no menu.js entry, no submenu.
  return {
    key: row.key,
    label: row.label || row.key,
    raw: true,
    custom: true,
    icon: row.icon || 'bar-chart',
    to: row.external ? undefined : (row.route || undefined),
    href: row.external ? (row.route || undefined) : undefined,
    permissions: [],
  };
}

/**
 * Same idea as buildTopLevelMenu(), but for the Manage Menus editor screen:
 * hidden rows are kept (flagged `hidden: true` on the returned node) instead
 * of dropped, so an admin can find and un-hide them. The live sidebar must
 * never call this one.
 */
export function buildTopLevelMenuForEditor(positions, menu = MENU) {
  if (!Array.isArray(positions) || !positions.length) return menu.map(entry => ({ ...entry, hidden: false }));

  const configMap = new Map(menu.map(entry => [entry.key, entry]));
  const topLevelPositions = positions.filter(p => !p.parent_key);
  const seen = new Set();
  const rows = [];

  topLevelPositions.forEach(p => {
    if (configMap.has(p.key) || p.is_custom) {
      rows.push(p);
      seen.add(p.key);
    }
  });
  menu.forEach((entry, index) => {
    if (!seen.has(entry.key)) rows.push({ key: entry.key, sort_order: 1000 + index });
  });
  rows.sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0));

  return rows.map(row => ({ ...resolveRow(row, configMap), hidden: !!row.hidden }));
}
