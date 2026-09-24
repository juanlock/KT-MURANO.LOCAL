/**
 * Shared by SidebarMenu.vue / SidebarLarge.vue / SidebarFlat.vue -- was
 * duplicated three times as a plain permissions check; now also handles
 * `systemOnly` entries (e.g. Login Page Settings), which only make sense
 * while "AdminSystem" (the reserved company_id=999 pseudo-company) is the
 * acting company in the topbar switcher -- they operate on install-wide
 * state, not anything scoped to a real company, so showing them under a
 * real company's context is misleading (a SuperAdmin browsing "Ruba Garden"
 * would see a menu item that doesn't belong to Ruba Garden at all).
 *
 * `superAdminOnly` is a separate concern: install-level admin tooling
 * (Modules, Upgrade, ZATCA, Backup, Login Device Management) that must
 * never be delegable to a company-level Admin's role, no matter which
 * company is acting or what permissions happen to be assigned to that
 * role. Checked against the logged-in user's actual `type`, not a
 * permission string, for the same reason Login Page Settings is gated by
 * type server-side -- a misconfigured role must not be able to grant it.
 */
export function isMenuEntryAllowed(entry, { can, actingCompanyId, isSuperAdmin }) {
    if (entry.systemOnly && actingCompanyId !== 999) return false;
    if (entry.superAdminOnly && !isSuperAdmin) return false;
    if (!entry.permissions || !entry.permissions.length) return true;
    return entry.permissions.some(p => can(p));
}
