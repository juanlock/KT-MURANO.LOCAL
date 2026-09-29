/**
 * Arranges the role editor's permissions (config/permissions.js catalogue)
 * along the acting company's own menu (config/menu.js + that company's
 * `menu_positions` rows -- see menuTree.js), so the editor reads top-down
 * exactly like the sidebar the role will see.
 *
 * Two kinds of permission per menu item:
 *  - menu permissions: the item's own `permissions` gate (sidebar shows the
 *    item when the role has ANY of them) -- rendered as the item's on/off
 *    switch. A permission shared by several items (e.g. Store_settings_view)
 *    belongs to all of them; flipping it flips every one of them, which is
 *    exactly what the sidebar would do too.
 *  - special permissions: the rest of the catalogue group that item's menu
 *    permission comes from (Add/Edit/Delete/...) -- rendered as checkboxes
 *    under the FIRST item (in this company's menu order) that uses that
 *    group. A group no menu item uses lands under a top-level entry whose
 *    label/key matches it (e.g. "dashboard"), else in the "Other" section.
 *
 * Nothing here decides access -- it only lays out the same flat
 * `permissions` array the role save endpoint always took.
 */

const norm = s => String(s || '').toLowerCase().replace(/[\s_-]+/g, '');

// Catalogue groups no menu item is gated by, but which clearly belong to one
// top-level entry (by its config/menu.js `key`): placed under that entry's
// first item (e.g. payments are managed from the Sales list).
const GROUP_HOME = {
  PaymentsSales: 'sales',
  PaymentsPurchases: 'purchases',
  PaymentsReturns: 'sale_return',
  // Keyed, not label-matched: a company may rename the entry (company 2
  // calls it "Repair & Service Maintenance").
  Service_Maintenance: 'service',
};

/**
 * @param {Array} tree       menu tree (buildTopLevelMenuForEditor output or MENU)
 * @param {Array} groups     catalogue groups [{label, raw?, items:[{v,l,f,...}]}]
 * @param {Object} opts
 *   - allowNode(entry) -> bool   drop nodes the editing user can't see (superAdminOnly…)
 *   - allowPerm(v) -> bool       drop permissions the editing user may not grant
 *   - moduleOn(entry) -> bool    false = module switched off (goes to its own section)
 * @returns {{ sections: Array, offSections: Array, other: Array }}
 */
export function buildPermissionTree(tree, groups, opts = {}) {
  const allowNode = opts.allowNode || (() => true);
  const allowPerm = opts.allowPerm || (() => true);
  const moduleOn = opts.moduleOn || (() => true);

  let seq = 0;
  const leavesInOrder = [];
  const nodesInOrder = [];

  function convert(entry, depth, parentOff) {
    if (!allowNode(entry)) return null;
    const off = parentOff || !moduleOn(entry);
    const isContainer = Array.isArray(entry.children);
    const node = {
      id: `n${seq++}`,
      entry,
      label: entry.label,
      raw: !!entry.raw,
      depth,
      hidden: !!entry.hidden,
      location: entry.location,
      off,
      isContainer,
      menuPerms: isContainer ? [] : (entry.permissions || []).filter(allowPerm),
      special: [],
      children: [],
    };
    nodesInOrder.push(node);
    if (!isContainer) leavesInOrder.push(node);
    if (isContainer) {
      node.children = entry.children.map(c => convert(c, depth + 1, off)).filter(Boolean);
    }
    return node;
  }

  const roots = tree.map(e => convert(e, 0, false)).filter(Boolean);

  // Every permission some menu item is gated by.
  const menuPermSet = new Set(leavesInOrder.flatMap(n => n.menuPerms));

  // How many menu items share each menu permission (for the "shared" hint).
  const shareCount = {};
  leavesInOrder.forEach(n => n.menuPerms.forEach(p => { shareCount[p] = (shareCount[p] || 0) + 1; }));

  const firstLeaf = n => (n.isContainer ? n.children.map(firstLeaf).find(Boolean) : n);

  const other = [];
  groups.forEach(group => {
    const items = group.items.filter(i => allowPerm(i.v));
    if (!items.length) return;
    const values = items.map(i => i.v);
    const extras = items.filter(i => !menuPermSet.has(i.v));
    if (!extras.length) return;

    // 0) a top-level single item named like the group (e.g. Dashboard,
    //    which needs no permission to show but owns the dashboard group)
    const g = norm(group.label);
    let target = roots.find(r => !r.isContainer && (norm(r.label) === g || norm(r.entry.key) === g));
    // 1) first leaf, in this company's menu order, gated by this group
    if (!target) target = leavesInOrder.find(n => n.menuPerms.some(p => values.includes(p)));
    // 2) else the first item of the entry the group is homed to -- wherever
    //    this company moved that entry in its menu
    if (!target && GROUP_HOME[group.label]) {
      const home = nodesInOrder.find(n => n.entry.key === GROUP_HOME[group.label]);
      if (home) target = firstLeaf(home) || home;
    }
    // 3) else any node whose label/key matches the group
    if (!target) {
      target = nodesInOrder.find(n => norm(n.label) === g || norm(n.entry.key) === g);
    }
    if (target) {
      target.special.push({ group, items: extras });
    } else {
      other.push({ group, items: extras });
    }
  });

  // Cached "everything under this node" list, for tri-state select-all.
  function collect(node) {
    const own = [...node.menuPerms, ...node.special.flatMap(s => s.items.map(i => i.v))];
    node.allPerms = [...new Set([...own, ...node.children.flatMap(collect)])];
    return node.allPerms;
  }
  roots.forEach(collect);

  const keep = n => n.allPerms.length > 0 || n.depth > 0;
  return {
    sections: roots.filter(n => !n.off && keep(n)),
    offSections: roots.filter(n => n.off && n.allPerms.length),
    other,
    shareCount,
  };
}

/**
 * What kind of action a catalogue permission is, for the editor's color
 * coding: view | add | edit | delete | other. Decided from the permission's
 * value (Sales_view, users_add, payment_sales_edit, ...) with the legacy
 * label (View/Add/Edit/Del) as a fallback.
 */
export function permKind(item) {
  const v = String(item.v || '').toLowerCase();
  const l = String(item.l || '').toLowerCase();
  if (/(^|_)(view|list|show)$/.test(v) || l === 'view') return 'view';
  if (/(^|_)(add|create|store|import)$/.test(v) || l === 'add') return 'add';
  if (/(^|_)(edit|update)$/.test(v) || l === 'edit') return 'edit';
  if (/(^|_)(delete|del|remove)$/.test(v) || l === 'del' || l === 'delete') return 'delete';
  return 'other';
}

/** Parent-first flat list of a section's rows, for table-style rendering. */
export function flattenNodes(nodes, depth = 0, out = []) {
  nodes.forEach(n => {
    out.push({ node: n, depth });
    if (n.children && n.children.length) flattenNodes(n.children, depth + 1, out);
  });
  return out;
}

/**
 * Search filter: keeps a node when its label, a menu permission or a special
 * permission matches `match(text)`; ancestors of a match stay so the tree
 * keeps its shape. Returns a new tree (null when nothing matches).
 */
export function filterPermissionNode(node, matchText, labelOf, itemLabelOf) {
  const selfHit = matchText(labelOf(node)) || node.menuPerms.some(p => matchText(p));
  const special = selfHit
    ? node.special
    : node.special
      .map(s => ({ ...s, items: s.items.filter(i => matchText(i.v) || matchText(itemLabelOf(i))) }))
      .filter(s => s.items.length);
  const children = node.children
    .map(c => filterPermissionNode(c, matchText, labelOf, itemLabelOf))
    .filter(Boolean);
  if (!selfHit && !special.length && !children.length) return null;
  return { ...node, special, children: selfHit ? node.children : children };
}
