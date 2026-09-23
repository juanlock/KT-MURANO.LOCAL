/**
 * Resolves a stored image value to a URL. Uploads used to store a bare
 * filename under one shared folder (e.g. products.image = "x.jpg" ->
 * /images/products/x.jpg); the backend now stores the full company-scoped
 * relative path for anything uploaded going forward (e.g.
 * "images/company_2/products/x.jpg"). A bare filename has no "/" in it, so
 * that's what distinguishes old-format values from new ones -- old records
 * keep resolving against their original shared folder, new ones resolve
 * as-is.
 */
export function resolveImageUrl(value, legacyFolder = '') {
  if (!value) return null;
  if (value.includes('/')) return `/${value}`;
  return legacyFolder ? `/images/${legacyFolder}/${value}` : `/images/${value}`;
}
