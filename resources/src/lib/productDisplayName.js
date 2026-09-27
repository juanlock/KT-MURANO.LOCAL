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

/** Kept for old callers still passing a (now nonexistent) sub_name -- always true. */
export function isMainSubName() {
  return true;
}
