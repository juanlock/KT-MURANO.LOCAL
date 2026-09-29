// Optional business modules the admin can switch on/off in System Settings →
// Modules. `key` MUST match the top-level entry key in config/menu.js — the
// sidebar filter and the router guard both key on it. Core commerce (products,
// sales, purchases, people, settings, reports…) is intentionally not listed:
// it can never be turned off.
//
// Flags live in settings.module_flags ({key: bool}) and reach the SPA on the
// get_user_auth payload (auth.user.module_flags). A null map or a missing key
// means the module is ENABLED — so existing installs see no change.

export const TOGGLEABLE_MODULES = [
    { key: 'Store', label: 'Store / E-commerce', description: 'Online storefront: orders, collections, banners, gallery, portfolio, pages, menus, imported website and Real Estate listings.', pathPrefixes: ['store', 'realestate'] },
    // One switch for both sidebar entries (Intranet for staff + Intranet Management).
    { key: 'intranet', label: 'Intranet', description: 'Staff intranet: news, files, gallery albums, events, calendar with birthdays, and pages — plus Intranet Management for admins.', pathPrefixes: ['intranet', 'intranet-admin'], menuKeys: ['intranet', 'intranet_management'] },
    { key: 'helpdesk', label: 'Helpdesk', description: 'Support tickets from employees, customers (portal), online customers (store), suppliers and email (mailbox import), handled by helpdesk agents.', pathPrefixes: ['helpdesk'] },
    { key: 'hrm', label: 'HRM', description: 'Employees, attendance, payroll, contracts and the knowledge base.', pathPrefixes: ['hrm', 'contracts'] },
    { key: 'recruit', label: 'Recruits & Jobs', description: 'Job postings, candidates, applications and interviews.', pathPrefixes: ['recruit'] },
    { key: 'meeting', label: 'Meetings', description: 'Meeting planning, attendance and reports.', pathPrefixes: ['meeting', 'meetings'] },
    { key: 'marketing', label: 'Marketing', description: 'Campaigns and marketing tools.', pathPrefixes: [] },
    { key: 'accounting', label: 'Accounting', description: 'Chart of accounts, journal entries, financial reports, expenses and deposits.', pathPrefixes: ['accounting-v2'] },
    { key: 'EWallet', label: 'E-Wallet', description: 'Customer wallet balances and wallet items.', pathPrefixes: ['ewallet'] },
    { key: 'commissions', label: 'Commissions', description: 'Agent commission programs, rules and receipts.', pathPrefixes: ['commissions'] },
    { key: 'promotions', label: 'Promotions', description: 'Discount promotions applied at the POS checkout.', pathPrefixes: ['promotions'] },
    { key: 'woocommerce_settings', label: 'WooCommerce', description: 'WooCommerce store synchronization.', pathPrefixes: [] },
    { key: 'shopify', label: 'Shopify', description: 'Shopify store synchronization and logs.', pathPrefixes: ['shopify'] },
    { key: 'salla', label: 'Salla', description: 'Salla store synchronization: products, stock and orders.', pathPrefixes: [] },
    { key: 'jumia', label: 'Jumia', description: 'Jumia marketplace: price & stock push, order import.', pathPrefixes: [] },
    { key: 'documents', label: 'Document Archive', description: 'Central document storage and archiving.', pathPrefixes: ['documents'] },
    { key: 'subscription_product', label: 'Subscription Products', description: 'Recurring product subscriptions.', pathPrefixes: ['subscriptions'] },
    { key: 'manufacturing', label: 'Manufacturing (MRP)', description: 'Bills of materials, production orders, work centers, quality and planning.', pathPrefixes: ['mrp'] },
    { key: 'assets', label: 'Asset Management', description: 'Company assets, assignments, maintenance, transfers and depreciation.', pathPrefixes: ['assets'] },
    { key: 'projects', label: 'Projects & Tasks', description: 'Projects, tasks, milestones, timesheets and project reports.', pathPrefixes: ['projects', 'tasks'] },
    { key: 'bookings', label: 'Booking Management', description: 'Bookings, calendar and trays.', pathPrefixes: ['bookings'] },
    { key: 'service', label: 'Service & Maintenance', description: 'Service jobs, technicians and checklists.', pathPrefixes: ['service'] },
    { key: 'fleet', label: 'Fleet Management', description: 'Vehicles, maintenance, fuel logs, assignments and fleet reports.', pathPrefixes: ['fleet'] },
    { key: 'hospital', label: 'Hospital Management', description: 'Patients, doctors, appointments, visits, admissions, wards, lab and billing.', pathPrefixes: ['hospital'] },
    { key: 'school', label: 'School Management', description: 'Students, teachers, academics, exams, timetable and fees.', pathPrefixes: [] },
];

const MODULE_KEYS = new Set(TOGGLEABLE_MODULES.map(m => m.key));

// A module can own several top-level menu entries (menuKeys): menu key -> module key.
const MENU_KEY_TO_MODULE = {};
for (const m of TOGGLEABLE_MODULES) {
    for (const k of (m.menuKeys || [])) MENU_KEY_TO_MODULE[k] = m.key;
}

// First URL segment -> module key, for the router guard.
const PREFIX_TO_MODULE = {};
for (const m of TOGGLEABLE_MODULES) {
    for (const p of m.pathPrefixes) PREFIX_TO_MODULE[p] = m.key;
}

/** True when `key` is enabled under the given flags map (null map = all on). */
export function isModuleEnabled(flags, key) {
    key = MENU_KEY_TO_MODULE[key] || key;
    if (!MODULE_KEYS.has(key)) return true; // not toggleable -> always on
    if (!flags || typeof flags !== 'object') return true;
    return flags[key] !== false;
}

/** Module key owning an SPA path ('/hospital/patients' -> 'hospital'), or null. */
export function moduleKeyForPath(path) {
    const seg = String(path || '').replace(/^\/+/, '').split('/')[0];
    return PREFIX_TO_MODULE[seg] || null;
}
