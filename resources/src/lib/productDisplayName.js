/**
 * Appends a product's sub_name disambiguator to its display name, matching
 * billing's convention: "name (sub_name)" -- skipped when sub_name is empty
 * or the default 'MAIN' (an ungrouped/single product with nothing to
 * disambiguate). Unrelated to true product_variants, whose "[Variant]" prefix
 * is baked into `name` server-side already.
 */
export function productDisplayName(name, subName) {
  const n = name || '';
  if (isMainSubName(subName)) return n;
  return `${n} (${subName.trim()})`;
}

/** True when there's nothing worth showing -- empty, or the default 'MAIN'. */
export function isMainSubName(subName) {
  const s = (subName || '').trim();
  return !s || s.toUpperCase() === 'MAIN';
}
