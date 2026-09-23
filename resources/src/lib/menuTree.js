/**
 * Merges an admin-saved layout (Settings -> Manage Menus, backed by the
 * `menu_positions` table -- see MenuPositionController) onto config/menu.js,
 * at EVERY level -- not just the top, and for ANY node (built-in or custom).
 *
 * Every node in the ENTIRE static tree (not just the top level) gets a
 * stable identity via nodeId() (menuOrder.js) -- `entry.key` at the top,
 * `group:<label>` for sub-groups, `to`/`href`/`label` for leaves -- already
 * documented there as stable across releases, so it doubles as the
 * `item_key`/`parent_key` a menu_positions row uses at any depth. That means
 * a `parent_key` override isn't limited to custom items: a BUILT-IN node can
 * be relocated too (e.g. dragging "Sales Return" to become a subgroup inside
 * "Sales") -- its `parent_key` row just points somewhere other than its
 * static default parent.
 *
 * `hasNestedPositions()` tells callers whether `positions` covers anything
 * below the top level. Until an admin actually uses the nested editor (Add
 * Submenu / Rename / Hide / relocate a node), it never does, and reordering
 * stays exactly as before: menuOrder.js's applyMenuOrder(), layered on top of
 * this module's output. The first full-tree save flips that permanently for
 * the install -- from then on every node has a menu_positions row, so this
 * module alone decides structure/order at every level and applyMenuOrder is
 * skipped (its `pool` is built from static config/menu.js only, so it has no
 * way to know about a relocated/custom node and would silently drop it).
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

/**
 * Walks the WHOLE static tree once, indexing every node (any depth) by its
 * nodeId(): { entry, defaultParentKey, defaultSortOrder }. defaultParentKey
 * is null for top-level entries -- this is what an un-relocated node falls
 * back to when no menu_positions row overrides its placement.
 */
function buildStaticIndex(menu) {
  const index = new Map();
  function walk(list, parentKey) {
    list.forEach((entry, i) => {
      const key = parentKey === null ? entry.key : nodeId(entry, false);
      index.set(key, { entry, defaultParentKey: parentKey, defaultSortOrder: i });
      if (entry.children) walk(entry.children, key);
    });
  }
  walk(menu, null);
  return index;
}

/**
 * Resolves `positions` + the static `menu` into a flat, uniform set of node
 * descriptors (static entries with overrides/relocation, plus fully custom
 * ones), groups them by their EFFECTIVE parent key, then builds the nested
 * tree from that grouping. Because placement is decided ONLY by each node's
 * resolved parentKey, a relocated node can never appear in two places (its
 * old static slot and its new one) the way a two-tier "top level vs.
 * recurse into static children" model risked.
 */
function buildFullTree(positions, menu, forEditor) {
  if (!Array.isArray(positions) || !positions.length) {
    return forEditor ? menu.map(entry => ({ ...entry, hidden: false })) : menu;
  }

  const staticIndex = buildStaticIndex(menu);
  const posByKey = new Map(positions.map(p => [p.key, p]));
  const nodes = new Map(); // key -> { key, parentKey, sortOrder, hidden, entry, location }

  staticIndex.forEach((info, key) => {
    const row = posByKey.get(key);
    if (row?.hidden && !forEditor) return; // hiding a container also hides its descendants (they're never built)
    const overrides = {};
    if (row?.label) { overrides.label = row.label; overrides.raw = true; }
    if (row?.icon) overrides.icon = row.icon;
    const entry = Object.keys(overrides).length ? { ...info.entry, ...overrides } : { ...info.entry };
    const parentKey = row ? (row.parent_key ?? null) : info.defaultParentKey;
    nodes.set(key, {
      key,
      parentKey,
      sortOrder: row ? row.sort_order : (1000 + info.defaultSortOrder),
      hidden: !!row?.hidden,
      entry,
      location: parentKey === null ? (row?.location || 'sidebar') : undefined,
    });
  });

  positions.forEach(row => {
    if (!row.is_custom || staticIndex.has(row.key)) return;
    if (row.hidden && !forEditor) return;
    // A "Menu" (pure container) vs. a "Link" is told apart by route
    // presence: the add-item form requires one for a Link and never sets
    // one for a Menu, so an empty route reliably means "this is a
    // container" -- without a `children` array here, SidebarMenu.vue's
    // render loop has no branch that matches a childless, routeless
    // top-level entry and it silently renders nothing.
    const entry = {
      key: row.key,
      label: row.label || row.key,
      raw: true,
      custom: true,
      icon: row.icon || 'bar-chart',
      to: row.external ? undefined : (row.route || undefined),
      href: row.external ? (row.route || undefined) : undefined,
      permissions: [],
    };
    if (!row.route && !row.external) entry.children = [];
    const parentKey = row.parent_key ?? null;
    nodes.set(row.key, {
      key: row.key,
      parentKey,
      sortOrder: row.sort_order,
      hidden: !!row.hidden,
      entry,
      location: parentKey === null ? (row.location || 'sidebar') : undefined,
    });
  });

  const childrenOf = new Map();
  nodes.forEach(node => {
    if (!childrenOf.has(node.parentKey)) childrenOf.set(node.parentKey, []);
    childrenOf.get(node.parentKey).push(node);
  });
  childrenOf.forEach(list => list.sort((a, b) => a.sortOrder - b.sortOrder));

  function build(parentKey) {
    return (childrenOf.get(parentKey) || []).map(node => {
      const built = { ...node.entry, __key: node.key };
      if (forEditor) built.hidden = node.hidden;
      if (node.location !== undefined) built.location = node.location;
      // Always resolve .children fresh for anything that was (or still is) a
      // container -- otherwise a container whose kids all got hidden or
      // relocated away would leak its raw, unprocessed static children array
      // through the initial {...node.entry} spread.
      if (node.entry.children || childrenOf.has(node.key)) {
        built.children = build(node.key);
      }
      return built;
    });
  }

  let top = build(null);
  if (!forEditor) top = top.filter(n => n.location === 'sidebar'); // see module docblock
  return top;
}

/**
 * Returns a new top-level array (with nested overrides/relocations applied
 * at every level -- see buildFullTree()) arranged per `positions` (the raw
 * rows from GET /api/menu-positions). Unknown/stale keys are ignored; any
 * MENU entry missing from `positions` keeps its default spot so nothing
 * silently disappears. Only 'sidebar' has a live renderer today -- items
 * tagged topnav/footer are saved (see Manage Menus) but stay out of the
 * sidebar until those rendering surfaces exist.
 */
export function buildTopLevelMenu(positions, menu = MENU) {
  return buildFullTree(positions, menu, false);
}

/**
 * Same idea as buildTopLevelMenu(), but for the Manage Menus editor screen:
 * hidden rows (at any level) are kept, flagged `hidden: true`, instead of
 * dropped, so an admin can find and un-hide them. The live sidebar must never
 * call this one.
 */
export function buildTopLevelMenuForEditor(positions, menu = MENU) {
  return buildFullTree(positions, menu, true);
}
