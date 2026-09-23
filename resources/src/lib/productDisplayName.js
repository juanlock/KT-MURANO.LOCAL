/**
 * A product's display name. Used to be "name (sub_name)" -- sub_name
 * disambiguated siblings within a Group Name before `product_groups`
 * existed as a real table; the column is gone now (2026_09_23_090000
 * migration), so this is just the name.
 */
export function productDisplayName(name) {
  return name || '';
}

/** Kept for old callers still passing a (now nonexistent) sub_name -- always true. */
export function isMainSubName() {
  return true;
}
