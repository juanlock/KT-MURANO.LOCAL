/**
 * A product's display name. Used to be "name (sub_name)" -- sub_name
 * disambiguated siblings within a Group Name before `product_groups`
 * existed as a real table; the column is gone now (2026_09_23_090000
 * migration), so this is just the name.
 *
 * One sub_name value survives as literal text baked into `name`: a group's
 * generic/base item, ported over as "{Group} (Main)" (e.g. "Landscape
 * Maintenance Services (Main)"). Showing "(Main)" on an invoice/line item
 * reads as a real sub-item to whoever receives it, so collapse it back down
 * to just the group name for display -- the underlying product record and
 * its stored name are untouched.
 */
export function productDisplayName(name) {
  if (!name) return '';
  return name.replace(/ \(Main\)$/i, '');
}

/**
 * Product search label with the Group Name shown once: names are stored as
 * "{Group} ({Item})", so "Group — Group (Item)" repeated the group. Gives
 * "Landscape Maintenance Services — Monthly", just the group for its
 * "(Main)" item, and the plain name when the product has no group or its
 * name doesn't follow that pattern.
 */
export function productSearchLabel(name, groupName) {
  const n = String(name || '');
  const g = String(groupName || '').trim();
  if (!g) return productDisplayName(n);
  const prefix = `${g} (`;
  if (n.startsWith(prefix) && n.endsWith(')')) {
    const item = n.slice(prefix.length, -1).trim();
    return !item || item.toLowerCase() === 'main' ? g : `${g} — ${item}`;
  }
  if (n === g || n.startsWith(`${g} `)) return productDisplayName(n);
  return `${g} — ${productDisplayName(n)}`;
}

/** Kept for old callers still passing a (now nonexistent) sub_name -- always true. */
export function isMainSubName() {
  return true;
}
