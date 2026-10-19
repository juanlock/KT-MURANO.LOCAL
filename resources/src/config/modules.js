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
    { key: 'Store', label: 'Store / E-commerce', description: 'Online storefront: orders, collections, banners, coupons, shipping, reviews and customers.', pathPrefixes: ['store'] },
    // Real Estate: its own switch since 2026-10-18 (it used to follow the Store switch and couldn't be turned off alone).
    { key: 'realestate', label: 'Real Estate', description: 'Property listings with photos, categories and the inquiries customers send about them.', pathPrefixes: ['realestate'] },
    // The company website (split from the Store, 2026-10-06): front page, homepage blocks, pages, menus, gallery, portfolio.
    { key: 'website', label: 'Website Management', description: 'The company website: front page (our website theme or an imported website), homepage blocks, hero, pages, menus, branding, SEO and social links — its own, separate from the online store.', pathPrefixes: ['website'] },
    // Gallery: its own module since 2026-10-06 (pictures for the website's gallery page / block).
    { key: 'gallery', label: 'Gallery', description: 'The company pictures: upload, order and show/hide them, or import them from the imported website. Shown on the website gallery page and its Gallery block.', pathPrefixes: ['gallery'] },
    // One switch for both sidebar entries (Intranet for staff + Intranet Management).
    { key: 'intranet', label: 'Intranet', description: 'Staff intranet: news, files, gallery albums, events, calendar with birthdays, and pages — plus Intranet Management for admins.', pathPrefixes: ['intranet', 'intranet-admin'], menuKeys: ['intranet', 'intranet_management'] },
    { key: 'email_office', label: 'Email Office', description: 'The company mailbox inside Murano: received emails sorted into bills (purchases / expenses), claims (helpdesk) and general, every email sent (reminders included), and each customer’s email conversation.', pathPrefixes: ['email-office'] },
    { key: 'portfolio', label: 'Portfolio', description: 'A public portfolio website for the company (/portfolio/your-name): works with photos, sliders or videos, filter categories, About and Contact pages, and the messages sent from it.', pathPrefixes: ['portfolio'] },
    { key: 'helpdesk', label: 'Helpdesk', description: 'Support tickets from employees, customers (portal), online customers (store), suppliers and email (mailbox import), handled by helpdesk agents.', pathPrefixes: ['helpdesk'] },
    { key: 'hrm', label: 'HRM', description: 'Employees, attendance, payroll, contracts and the knowledge base.', pathPrefixes: ['hrm', 'contracts'] },
    { key: 'recruit', label: 'Recruits & Jobs', description: 'Job postings, candidates, applications and interviews.', pathPrefixes: ['recruit'] },
    { key: 'meeting', label: 'Meetings', description: 'Meeting planning, attendance and reports.', pathPrefixes: ['meeting', 'meetings'] },
    { key: 'marketing', label: 'Marketing', description: 'Campaigns and marketing tools.', pathPrefixes: ['marketing'] },
    { key: 'accounting', label: 'Accounting', description: 'Chart of accounts, journal entries, financial reports, expenses and deposits.', pathPrefixes: ['accounting-v2'] },
    { key: 'EWallet', label: 'E-Wallet', description: 'Customer wallet balances and wallet items.', pathPrefixes: ['ewallet'] },
    { key: 'commissions', label: 'Commissions', description: 'Agent commission programs, rules and receipts.', pathPrefixes: ['commissions'] },
    { key: 'promotions', label: 'Promotions', description: 'Discount promotions applied at the POS checkout.', pathPrefixes: ['promotions'] },
    { key: 'woocommerce_settings', label: 'WooCommerce', description: 'WooCommerce store synchronization.', pathPrefixes: ['woocommerce'] },
    { key: 'shopify', label: 'Shopify', description: 'Shopify store synchronization and logs.', pathPrefixes: ['shopify'] },
    { key: 'salla', label: 'Salla', description: 'Salla store synchronization: products, stock and orders.', pathPrefixes: ['integrations/salla'] },
    { key: 'jumia', label: 'Jumia', description: 'Jumia marketplace: price & stock push, order import.', pathPrefixes: ['integrations/jumia'] },
    // Switches added 2026-10-18 (the menu already had their keys; there was no switch).
    { key: 'prestashop', label: 'PrestaShop', description: 'PrestaShop store synchronization: products, stock and orders.', pathPrefixes: ['integrations/prestashop'] },
    { key: 'quickbooks', label: 'QuickBooks', description: 'QuickBooks Online connection: customers, products, invoices and payments synchronization.', pathPrefixes: ['settings/quickbooks'] },
    { key: 'xero', label: 'Xero', description: 'Xero accounting connection: contacts, invoices and payments synchronization.', pathPrefixes: ['integrations/xero'] },
    { key: 'google_sheets', label: 'Google Sheets', description: 'Export / sync sales, products and customers to Google Sheets.', pathPrefixes: ['integrations/google-sheets'] },
    { key: 'mailchimp', label: 'Mailchimp', description: 'Send customers to Mailchimp audiences for email campaigns.', pathPrefixes: ['integrations/mailchimp'] },
    { key: 'slack', label: 'Slack', description: 'Notifications to a Slack channel (new sales, low stock…).', pathPrefixes: ['integrations/slack'] },
    { key: 'telegram', label: 'Telegram', description: 'Notifications to a Telegram chat (new sales, low stock…).', pathPrefixes: ['integrations/telegram'] },
    { key: 'kitchen', label: 'Kitchen', description: 'Kitchen display for restaurant orders and the kitchen report.', pathPrefixes: ['kitchen-display', 'kitchen'] },
    // Saudi Arabia only (2026-10-18): OFF unless a SuperAdmin turns it on for a company.
    // PayPal (2026-10-19): customers pay the company's invoices with ITS OWN PayPal account ("Pay now"). Off by default.
    { key: 'paypal', label: 'PayPal', description: 'Customers pay their invoices with PayPal ("Pay now" link on the invoice): the payment is recorded on the sale by itself, in the company\'s own PayPal account. Off by default.', pathPrefixes: ['settings/paypal'], defaultOff: true },
    { key: 'zatca', label: 'ZATCA E-Invoicing (Saudi Arabia)', description: 'KSA e-invoicing: phase 1 QR code on invoices / receipts and phase 2 Fatoora reporting of sales and returns. Off by default -- only for companies in Saudi Arabia.', pathPrefixes: ['settings/zatca'], defaultOff: true },
    { key: 'documents', label: 'Document Archive', description: 'Central document storage and archiving.', pathPrefixes: ['documents'] },
    { key: 'subscription_product', label: 'Subscription Products', description: 'Recurring product subscriptions.', pathPrefixes: ['subscriptions'] },
    { key: 'manufacturing', label: 'Manufacturing (MRP)', description: 'Bills of materials, production orders, work centers, quality and planning.', pathPrefixes: ['mrp'] },
    { key: 'assets', label: 'Asset Management', description: 'Company assets, assignments, maintenance, transfers and depreciation.', pathPrefixes: ['assets'] },
    { key: 'projects', label: 'Projects & Tasks', description: 'Projects, tasks, milestones, timesheets and project reports.', pathPrefixes: ['projects', 'tasks'] },
    { key: 'bookings', label: 'Booking Management', description: 'Bookings, calendar and trays.', pathPrefixes: ['bookings'] },
    { key: 'service', label: 'Service & Maintenance', description: 'Service jobs, technicians and checklists.', pathPrefixes: ['service'] },
    { key: 'fleet', label: 'Fleet Management', description: 'Vehicles, maintenance, fuel logs, assignments and fleet reports.', pathPrefixes: ['fleet'] },
    { key: 'hospital', label: 'Hospital Management', description: 'Patients, doctors, appointments, visits, admissions, wards, lab and billing.', pathPrefixes: ['hospital'] },
    { key: 'school', label: 'School Management', description: 'Students, teachers, academics, exams, timetable and fees.', pathPrefixes: ['school'] },
];

const MODULE_KEYS = new Set(TOGGLEABLE_MODULES.map(m => m.key));

// A module can own several top-level menu entries (menuKeys): menu key -> module key.
const MENU_KEY_TO_MODULE = {};
for (const m of TOGGLEABLE_MODULES) {
    for (const k of (m.menuKeys || [])) MENU_KEY_TO_MODULE[k] = m.key;
}

// URL prefix -> module key, for the router guard: one segment ('hospital') or
// two ('integrations/xero' -- integrations share their first segment).
const PREFIX_TO_MODULE = {};
for (const m of TOGGLEABLE_MODULES) {
    for (const p of m.pathPrefixes) PREFIX_TO_MODULE[p] = m.key;
}

// Modules that are OFF until turned on (ZATCA). Keep in sync with
// App\Support\ModuleFlags::DEFAULT_OFF.
const DEFAULT_OFF = new Set(TOGGLEABLE_MODULES.filter(m => m.defaultOff).map(m => m.key));

/** True when `key` is enabled under the given flags map (null map / missing key = on, except DEFAULT_OFF modules). */
export function isModuleEnabled(flags, key) {
    key = MENU_KEY_TO_MODULE[key] || key;
    if (!MODULE_KEYS.has(key)) return true; // not toggleable -> always on
    const value = flags && typeof flags === 'object' ? flags[key] : undefined;
    if (DEFAULT_OFF.has(key)) return value === true;
    return value !== false;
}

/**
 * A menu entry is shown only when its own module (entry.key) AND the module of
 * the page it opens (its path: '/hospital/reports' -> hospital) are on -- so an
 * item kept under Reports, or moved into another group in Manage Menu, still
 * follows its module's switch. pathOf maps a menu 	o to the SPA path.
 */
export function isEntryEnabled(flags, entry, pathOf) {
    if (!isModuleEnabled(flags, entry.key)) return false;
    const path = entry.to && pathOf ? pathOf(entry.to) : null;
    const mod = path ? moduleKeyForPath(path) : null;
    return !mod || isModuleEnabled(flags, mod);
}

/** Module key owning an SPA path ('/hospital/patients' -> 'hospital'), or null. */
export function moduleKeyForPath(path) {
    const segs = String(path || '').replace(/^\/+/, '').split('/');
    return PREFIX_TO_MODULE[`${segs[0]}/${segs[1] ?? ''}`] || PREFIX_TO_MODULE[segs[0]] || null;
}
