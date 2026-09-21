/**
 * Applies the admin-saved sidebar arrangement (Settings → Sidebar Menu) on top
 * of the default MENU from config/menu.js.
 *
 * The saved order is a tree of ids: [{ id, children?: [{ id, children? }] }].
 * Ids are stable across releases: top-level entries use their `key`, sub-groups
 * use `group:<label>`, leaves use their path (`to`/`href`). All 248 are unique.
 *
 * The merge is defensive so an order saved on an older release keeps working:
 * - ids that no longer exist in MENU are dropped;
 * - entries missing from the saved order (new modules/pages) are appended at
 *   the end of their default parent, in default relative order;
 * - structure rules are enforced (a leaf can never become a top-level module,
 *   a module can never be nested, sub-groups only sit directly under a module),
 *   so a malformed saved tree degrades gracefully instead of corrupting the menu.
 */
import { MENU } from '../config/menu';

/** Stable id for a menu entry. `isTop` = a top-level module from MENU. */
export function nodeId(entry, isTop = false) {
    if (isTop) return entry.key;
    if (entry.children) return `group:${entry.label}`;
    return entry.to || entry.href || entry.label;
}

/** Serialize the default MENU into the id-tree shape (the "factory" order). */
export function defaultOrder(menu = MENU) {
    const child = e => (e.children
        ? { id: nodeId(e), children: e.children.map(child) }
        : { id: nodeId(e) });
    return menu.map(g => (g.children
        ? { id: g.key, children: g.children.map(child) }
        : { id: g.key }));
}

/**
 * Returns a new MENU-shaped array arranged per `order`. Entry objects are the
 * originals (labels/permissions/icons untouched); only container `children`
 * arrays are rebuilt. Falls back to segments of the default order for anything
 * the saved tree doesn't cover.
 */
export function applyMenuOrder(order, menu = MENU) {
    if (!Array.isArray(order) || !order.length) return menu;

    // Pool of every movable (non-top-level) entry: id → { entry, parentId }.
    const pool = new Map();
    for (const group of menu) {
        for (const c of group.children || []) {
            pool.set(nodeId(c), { entry: c, parentId: group.key });
            for (const leaf of c.children || []) {
                pool.set(nodeId(leaf), { entry: leaf, parentId: nodeId(c) });
            }
        }
    }

    const topMap = new Map(menu.map(g => [g.key, g]));
    const consumed = new Set();
    // Container id → the rebuilt children array to append leftovers into.
    const containers = new Map();

    /** Resolve one saved child node. depth 1 = directly under a module. */
    function resolveChild(node, depth) {
        const rec = pool.get(node && node.id);
        if (!rec || consumed.has(node.id)) return null;
        const { entry } = rec;
        // Sub-groups may only sit directly under a top-level module.
        if (entry.children && depth > 1) return null;
        consumed.add(node.id);
        if (!entry.children) return entry;
        const kids = (node.children || [])
            .map(k => resolveChild(k, depth + 1))
            .filter(Boolean);
        const clone = { ...entry, children: kids };
        containers.set(node.id, kids);
        return clone;
    }

    const result = [];
    const usedTop = new Set();
    for (const node of order) {
        const group = topMap.get(node && node.id);
        if (!group || usedTop.has(node.id)) continue;
        usedTop.add(node.id);
        if (!group.children) {
            result.push(group);
            continue;
        }
        const kids = (node.children || [])
            .map(k => resolveChild(k, 1))
            .filter(Boolean);
        const clone = { ...group, children: kids };
        containers.set(group.key, kids);
        result.push(clone);
    }

    // Modules absent from the saved order keep their default spot at the end,
    // with whatever children weren't claimed elsewhere.
    for (const group of menu) {
        if (usedTop.has(group.key)) continue;
        if (!group.children) {
            result.push(group);
            continue;
        }
        const kids = [];
        for (const c of group.children) {
            const cid = nodeId(c);
            if (consumed.has(cid)) continue;
            consumed.add(cid);
            if (!c.children) {
                kids.push(c);
                continue;
            }
            const leaves = c.children.filter(l => !consumed.has(nodeId(l)));
            leaves.forEach(l => consumed.add(nodeId(l)));
            const sub = { ...c, children: leaves };
            containers.set(cid, sub.children);
            kids.push(sub);
        }
        containers.set(group.key, kids);
        result.push({ ...group, children: kids });
    }

    // Any entry still unplaced (new in this release, or its saved node was
    // invalid) goes to the end of its default parent.
    for (const [id, rec] of pool) {
        if (consumed.has(id)) continue;
        consumed.add(id);
        let target = containers.get(rec.parentId);
        if (!target) {
            // Default parent vanished from the rebuilt tree (e.g. its sub-group
            // node was dropped as invalid) — climb to the owning module.
            const owner = menu.find(g => (g.children || []).some(
                c => nodeId(c) === rec.parentId || nodeId(c) === id
            ));
            target = owner ? containers.get(owner.key) : null;
        }
        if (!target) continue;
        if (rec.entry.children) {
            const leaves = rec.entry.children.filter(l => !consumed.has(nodeId(l)));
            leaves.forEach(l => consumed.add(nodeId(l)));
            const sub = { ...rec.entry, children: leaves };
            containers.set(id, sub.children);
            target.push(sub);
        } else {
            target.push(rec.entry);
        }
    }

    return result;
}
