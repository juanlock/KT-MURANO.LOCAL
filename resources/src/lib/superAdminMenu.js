/**
 * Menu items only the SuperAdmin sees (user, 2026-11-23): Manage Menu as AdminSystem marks an item or a whole group
 * "Solo SuperAdmin"; the keys (menu_positions item keys: a top-level `key`, `group:<label>` for sub-groups, the `to`
 * of a page) arrive as auth.user.superadmin_only_menu. The sidebars hide them for everyone else (the entry gets
 * superAdminOnly, which isMenuEntryAllowed already honours) and the router refuses their pages by URL.
 */
import { MENU, resolveTarget } from '../config/menu';
import { nodeId } from './menuOrder';

// Same as stores/menu.js spaPathOf (not imported from there: that store imports this file).
const spaPathOf = (to) => {
  const r = resolveTarget(to);
  return r && r.type === 'next' ? r.path : null;
};

const keyOf = (entry, isTop) => entry.__key || (isTop ? entry.key : nodeId(entry, false));

/** The tree with every restricted node (and so everything inside it) marked superAdminOnly. */
export function markSuperAdminOnly(tree, keys) {
  const set = new Set(keys || []);
  if (!set.size) return tree;
  const walk = (list, isTop) => list.map((e) => {
    const restricted = set.has(keyOf(e, isTop));
    const out = restricted ? { ...e, superAdminOnly: true } : e;
    return out.children ? { ...out, children: walk(out.children, false) } : out;
  });
  return walk(tree, true);
}

/** The SPA paths of every page inside a restricted node (by the code's menu), for the router guard. */
export function superAdminOnlyPaths(keys) {
  const set = new Set(keys || []);
  const paths = new Set();
  if (!set.size) return paths;
  const walk = (list, isTop, inside) => list.forEach((e) => {
    const restricted = inside || set.has(keyOf(e, isTop));
    if (restricted && e.to) {
      const p = spaPathOf(e.to);
      if (p) paths.add(p);
    }
    if (e.children) walk(e.children, false, restricted);
  });
  walk(MENU, true, false);
  return paths;
}
