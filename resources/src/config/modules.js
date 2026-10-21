// Optional business modules the admin can switch on/off in System Settings →
// Modules. `key` MUST match the top-level entry key in config/menu.js — the
// sidebar filter and the router guard both key on it. Settings and reports are
// intentionally not listed: they can never be turned off (Sales, POS,
// Purchases, Suppliers, Products and Customers can, since 2026-10-20).
//
// Flags live in settings.module_flags ({key: bool}) and reach the SPA on the
// get_user_auth payload (auth.user.module_flags). A null map or a missing key
// means the module is ENABLED — so existing installs see no change.

export const TOGGLEABLE_MODULES = [
    // Commerce areas a company may not use (user's rule, 2026-10-20: a hospital
    // bills through Hospital Management, not Sales / POS / Purchases). On for
    // every business type except Hospital / clinic (App\Support\BusinessTypes).
    // Their reports (Reports › …) follow them too: two-segment prefixes 'reports/x'.
    { key: 'sales', label: 'Sales', description: 'Sales, sales returns, quotations, sales contracts, shipments, the real-time sales counter and the sales reports.', pathPrefixes: ['sales', 'sale-returns', 'quotations', 'sales-contracts', 'shipments', 'real-time-sales-counter',
        'reports/sales', 'reports/product-sales', 'reports/products-sold-summary', 'reports/sales-by-category', 'reports/sales-by-brand', 'reports/top-selling-products',
        'reports/top-customers', 'reports/seller', 'reports/draft-invoices', 'reports/return-ratio', 'reports/zero-sales', 'reports/payments-sale', 'reports/payments-sales-returns',
        'reports/sales-3d-dashboard', 'reports/inactive-customers', 'reports/loyalty-points', 'reports/profit'], menuKeys: ['sales', 'sale_return', 'quotations'] },
    { key: 'pos', label: 'POS', description: 'Point of sale: the POS screen, POS sales, the customer display, POS settings and the cash registers report.', pathPrefixes: ['pos', 'pos-sales', 'customer-display', 'settings/pos', 'reports/cash-registers'] },
    { key: 'purchases', label: 'Purchases', description: 'Purchases, purchase returns and the purchase reports.', pathPrefixes: ['purchases', 'purchase-returns',
        'reports/purchases', 'reports/product-purchases', 'reports/payments-purchase', 'reports/payments-purchases-returns'], menuKeys: ['purchases', 'purchase_return'] },
    { key: 'suppliers', label: 'Suppliers', description: 'The suppliers list (People › Suppliers), their imports and the supplier reports.', pathPrefixes: ['suppliers', 'reports/suppliers', 'reports/top-suppliers'] },
    // Products & stock, and Customers (switchable since 2026-10-20: a school uses
    // neither -- students, not customers; fees, not products).
    { key: 'products', label: 'Products & Stock', description: 'Products and services, categories, brands, units, stock adjustments, transfers, damages, serial numbers and the stock reports.', pathPrefixes: ['products', 'brands', 'categories', 'subcategories', 'units', 'size-guides', 'adjustments', 'transfers', 'damages', 'serial-numbers',
        'reports/dead-stock', 'reports/negative-stock', 'reports/expiry', 'reports/warranty', 'reports/stock-adjustment', 'reports/serial-sold', 'reports/serial-available', 'reports/serial-movement', 'reports/serial-inventory',
        'reports/batch-register', 'reports/quantity-alerts', 'reports/stock', 'reports/product', 'reports/transactions', 'reports/internal-location', 'reports/stock-transfer', 'reports/stock-aging',
        'reports/inventory-valuation-summary', 'reports/stock-inventory-valuation', 'reports/analytics'], menuKeys: ['products', 'adjustments', 'transfers', 'damages'] },
    { key: 'customers', label: 'Customers', description: 'The customers list (People › Customers), the customer portal and the customer reports.', pathPrefixes: ['customers', 'customers-with-login', 'customers-without-login', 'reports/customers', 'portal'] },
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
    // Integrations (user's rule, 2026-10-20): OFF by default for every company --
    // their settings are install-wide (one row for the whole system), so only a
    // SuperAdmin turns one on for a company that really uses it.
    { key: 'integrations', label: 'Integrations hub & Webhooks', description: 'The Integrations page (connect other systems) and Webhooks (send Murano events -- new sale, payment... -- to another system\'s address). Off by default.', pathPrefixes: ['integrations', 'settings/webhooks'], menuKeys: ['integrations', 'ecommerce_platforms'], defaultOff: true },
    { key: 'woocommerce_settings', label: 'WooCommerce', description: 'WooCommerce store synchronization. Off by default.', pathPrefixes: ['woocommerce'], defaultOff: true },
    { key: 'shopify', label: 'Shopify', description: 'Shopify store synchronization and logs. Off by default.', pathPrefixes: ['shopify'], defaultOff: true },
    { key: 'salla', label: 'Salla', description: 'Salla store synchronization: products, stock and orders. Off by default.', pathPrefixes: ['integrations/salla'], defaultOff: true },
    { key: 'jumia', label: 'Jumia', description: 'Jumia marketplace: price & stock push, order import. Off by default.', pathPrefixes: ['integrations/jumia'], defaultOff: true },
    // Switches added 2026-10-18 (the menu already had their keys; there was no switch).
    { key: 'prestashop', label: 'PrestaShop', description: 'PrestaShop store synchronization: products, stock and orders. Off by default.', pathPrefixes: ['integrations/prestashop'], defaultOff: true },
    { key: 'quickbooks', label: 'QuickBooks', description: 'QuickBooks Online connection: customers, products, invoices and payments synchronization. Off by default.', pathPrefixes: ['settings/quickbooks'], defaultOff: true },
    { key: 'xero', label: 'Xero', description: 'Xero accounting connection: contacts, invoices and payments synchronization. Off by default.', pathPrefixes: ['integrations/xero'], defaultOff: true },
    { key: 'google_sheets', label: 'Google Sheets', description: 'Export / sync sales, products and customers to Google Sheets. Off by default.', pathPrefixes: ['integrations/google-sheets'], defaultOff: true },
    { key: 'mailchimp', label: 'Mailchimp', description: 'Send customers to Mailchimp audiences for email campaigns. Off by default.', pathPrefixes: ['integrations/mailchimp'], defaultOff: true },
    { key: 'slack', label: 'Slack', description: 'Notifications to a Slack channel (new sales, low stock…). Off by default.', pathPrefixes: ['integrations/slack'], defaultOff: true },
    { key: 'telegram', label: 'Telegram', description: 'Notifications to a Telegram chat (new sales, low stock…). Off by default.', pathPrefixes: ['integrations/telegram'], defaultOff: true },
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

// Every module on: what the SuperAdmin's menu / router use (user's rule,
// 2026-10-19: the SuperAdmin sees and opens every module of every company;
// the company's own switches still apply to its users).
export const ALL_MODULES_ON = Object.fromEntries(TOGGLEABLE_MODULES.map(m => [m.key, true]));

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
    // Links outside the SPA (href: '/portal') follow their module too.
    const path = entry.to && pathOf ? pathOf(entry.to) : (typeof entry.href === 'string' && entry.href.startsWith('/') ? entry.href : null);
    const mod = path ? moduleKeyForPath(path) : null;
    return !mod || isModuleEnabled(flags, mod);
}

/** Module key owning an SPA path ('/hospital/patients' -> 'hospital'), or null. */
export function moduleKeyForPath(path) {
    const segs = String(path || '').replace(/^\/+/, '').split('/');
    return PREFIX_TO_MODULE[`${segs[0]}/${segs[1] ?? ''}`] || PREFIX_TO_MODULE[segs[0]] || null;
}
