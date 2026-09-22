/**
 * Merges an admin-saved layout (Settings -> Manage Menus, backed by the
 * `menu_positions` table -- see MenuPositionController) onto config/menu.js,
 * at EVERY level -- not just the top.
 *
 * Ported from KT-Billing's menuTree.js. Only top-level entries in MENU have a
 * stable `key` field; sub-items are identified by nodeId() (menuOrder.js) --
 * `group:<label>` for sub-groups, `to`/`href`/`label` for leaves -- which is
 * already documented there as stable across releases, so it doubles as the
 * `item_key`/`parent_key` a nested menu_positions row uses.
 *
 * `hasNestedPositions()` tells callers whether `positions` covers anything
 * below the top level. Until an admin actually uses the nested editor (Add
 * Submenu / Rename / Hide on a sub-item), it never does, and reordering stays
 * exactly as before: menuOrder.js's applyMenuOrder(), layered on top of this
 * module's output. The first full-tree save flips that permanently for the
 * install -- from then on every node has a menu_positions row, so this module
 * alone decides structure/order at every level and applyMenuOrder is skipped
 * (its `pool` is built from static config/menu.js only, so it has no way to
 * know about a custom sub-item created here and would silently drop it).
 */
import { MENU } from '../config/menu';
import { nodeId } from './menuOrder';

/** Serializes MENU's top level into the { key, sort_order } shape saved rows use. */
export function flattenTopLevelDefaults(menu = MENU) {
  return menu.map((entry, index) => ({ key: entry.key, sort_order: index }));
}

/** True once `positions` has at least one row below the top level. */
export function hasNestedPositions(positions) {
  return Array.isArray(positions) && positions.some(p => p.parent_key);
}

function resolveRow(row, configMap) {
  const location = row.location || 'sidebar';
  const base = configMap.get(row.key);
  if (base) {
    // A renamed and/or re-iconed built-in module: keep its children/permissions.
    const overrides = { location };
    if (row.label) { overrides.label = row.label; overrides.raw = true; }
    if (row.icon) overrides.icon = row.icon;
    return { ...base, ...overrides };
  }
  // A fully custom module created via Manage Menus -- no menu.js entry.
  return {
    key: row.key,
    label: row.label || row.key,
    raw: true,
    custom: true,
    icon: row.icon || 'bar-chart',
    to: row.external ? undefined : (row.route || undefined),
    href: row.external ? (row.route || undefined) : undefined,
    permissions: [],
    location,
  };
}

/**
 * Applies menu_positions overrides (label/icon/hidden) and injects custom
 * items onto one container's static `children`, then recurses. `byKey` /
 * `childrenByParent` are pre-indexed once per build (see buildTree() below).
 * Every returned node carries `__key` -- the identity to use when this tree
 * is flattened back to rows, since a custom item's real key (`custom_xxxx`)
 * can't be recomputed from its label/route the way nodeId() derives one for
 * static entries.
 */
function applyPositionsToChildren(children, parentKey, byKey, childrenByParent, forEditor) {
  const seen = new Set();
  const result = [];

  children.forEach(entry => {
    const key = nodeId(entry, false);
    seen.add(key);
    const row = byKey.get(key);
    if (row?.hidden && !forEditor) return;

    const overrides = {};
    if (row?.label) { overrides.label = row.label; overrides.raw = true; }
    if (row?.icon) overrides.icon = row.icon;
    const node = Object.keys(overrides).length ? { ...entry, ...overrides } : { ...entry };
    node.__key = key;
    if (forEditor) node.hidden = !!row?.hidden;

    if (entry.children) {
      node.children = applyPositionsToChildren(entry.children, key, byKey, childrenByParent, forEditor);
    }
    result.push(node);
  });

  (childrenByParent.get(parentKey) || []).forEach(row => {
    if (!row.is_custom || seen.has(row.key)) return;
    if (row.hidden && !forEditor) return;
    result.push({
      __key: row.key,
      label: row.label || row.key,
      raw: true,
      custom: true,
      icon: row.icon || undefined,
      to: row.external ? undefined : (row.route || undefined),
      href: row.external ? (row.route || undefined) : undefined,
      permissions: [],
      hidden: forEditor ? !!row.hidden : false,
    });
  });

  result.sort((a, b) => {
    const ra = byKey.get(a.__key);
    const rb = byKey.get(b.__key);
    const sa = (ra && ra.sort_order != null) ? ra.sort_order : 1000;
    const sb = (rb && rb.sort_order != null) ? rb.sort_order : 1000;
    return sa - sb;
  });

  return result;
}

/**
 * Shared by buildTopLevelMenu()/buildTopLevelMenuForEditor(): resolves the
 * top level, then applies the same override/injection pass recursively to
 * every level below it.
 */
function buildTree(positions, menu, forEditor) {
  if (!Array.isArray(positions) || !positions.length) {
    return forEditor ? menu.map(entry => ({ ...entry, hidden: false })) : menu;
  }

  const byKey = new Map(positions.map(p => [p.key, p]));
  const childrenByParent = new Map();
  positions.forEach(p => {
    if (!p.parent_key) return;
    if (!childrenByParent.has(p.parent_key)) childrenByParent.set(p.parent_key, []);
    childrenByParent.get(p.parent_key).push(p);
  });

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
    if (row.hidden && !forEditor) return acc;
    const node = resolveRow(row, configMap);
    if (!forEditor && node.location !== 'sidebar') return acc; // see module docblock
    if (forEditor) node.hidden = !!row.hidden;
    if (node.children) {
      node.children = applyPositionsToChildren(node.children, node.key, byKey, childrenByParent, forEditor);
    }
    acc.push(node);
    return acc;
  }, []);
}

/**
 * Returns a new top-level array (with nested overrides/custom items applied
 * at every level -- see buildTree()) arranged per `positions` (the raw rows
 * from GET /api/menu-positions). Unknown/stale keys are ignored; any MENU
 * entry missing from `positions` (e.g. a brand-new module) keeps its default
 * spot at the end so nothing silently disappears. Only 'sidebar' has a live
 * renderer today -- items tagged topnav/footer are saved (see Manage Menus)
 * but stay out of the sidebar until those rendering surfaces exist.
 */
export function buildTopLevelMenu(positions, menu = MENU) {
  return buildTree(positions, menu, false);
}

/**
 * Same idea as buildTopLevelMenu(), but for the Manage Menus editor screen:
 * hidden rows (at any level) are kept, flagged `hidden: true`, instead of
 * dropped, so an admin can find and un-hide them. The live sidebar must never
 * call this one.
 */
export function buildTopLevelMenuForEditor(positions, menu = MENU) {
  return buildTree(positions, menu, true);
}
