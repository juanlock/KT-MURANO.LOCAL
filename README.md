# KT-Murano

KT-Murano ("the new system") is a multi-company business platform: POS, sales,
purchasing, inventory, service jobs, bookings, HRM, accounting, Email Office,
portfolio sites and more. It replaces the older **KT-Billing** system, which is
still in production.

One install serves several companies (KT, Ruba, NewEra, ...). Each company sees
and edits only its own data.

> Built on **Stocky v5.8** (a commercial CodeCanyon Laravel product). Many
> modules in the codebase (hospital, school, fleet, MRP...) are stock Stocky
> features. The multi-company layer, the dynamic menu and the modules ported
> from billing are this project's own work.

---

## Stack

| Layer    | Tech |
|----------|------|
| Backend  | PHP 8.2+, Laravel 12, Passport (API auth), MySQL / MariaDB |
| Frontend | Vue 3 SPA + Ant Design Vue 4, built with Vite 6 (served under `/next`) |
| Other    | Customer display, customer portal and storefront: separate Vite builds |

The app runs locally at `http://kt-murano.local/next` with database `dbmurano`.

---

## Getting started (new machine)

```bash
git clone https://github.com/juanlock/KT-MURANO.LOCAL.git
cd KT-MURANO.LOCAL

composer install
npm ci

```

Check `.env` before running anything: database credentials, `APP_URL` and
mail settings. There is no `.env.example`; take a working `.env` from another
machine. Never commit `.env` changes, because it holds this machine's secrets.

1. Create the `dbmurano` database. Import the latest backup from
   `KT-TOOLS/SQL/`: first `backup db.zip`, then
   `backup db office emails 1.zip`, `2.zip` and `3.zip`, in that order.
   Unzip each one first.
2. Run `php artisan migrate` to apply anything newer than the backup.
3. Run `npm run build`. `public/js` is not in git, so this step is required.
4. Run `php artisan optimize:clear`.

Keep `APP_TIMEZONE=America/New_York` in `.env`. The business runs on Eastern
time, and the old `UTC` value was a bug.

### Day-to-day

```bash
npm run build      # full frontend build (admin + customer display + portal + storefront)
npm run watch      # rebuild the admin SPA on change
php artisan migrate
```

---

## Project layout

```
app/
  Http/Controllers/      API controllers (company-scoped via Concerns/ScopesToCompany)
  Models/                Eloquent models
  Services/              Domain services (EmailOffice, LinkedUserService, PayrollRun, ...)
  Support/               Helpers: CompanyFiles, EmployeeTabs, ServiceJobLinks, ServiceJobCost, ModuleFlags, WebsitePaths, SuperAdminAccess, CompanyProfile, ListSort, CompanyMail...
database/
  migrations/            Schema + data migrations (run on every machine)
  scripts/               Protocol scripts (backups, reports)
resources/src/           Vue 3 SPA
  pages/                 One folder per module
  components/  lib/      Shared UI and helpers (PresetRangeFilter, sortByLabel, goBack...)
  config/                Menu, permissions, dashboard sections
  manual/manual.js       In-app user manual (EN + ES), by module
public/files/company_{id}/{module}/   Uploaded / generated files, per company
public/documentation/    Stocky's user guide + REST API reference (OpenAPI)
KT-TOOLS/                Backups (database/, SQL/), reports (Report/), user manual (Manual/), tools
.claude/commands/        Session protocols (see below)
CLAUDE.md                Project rules and conventions (read this first)
```

---

## Key rules

The full list is in [CLAUDE.md](CLAUDE.md). The most important ones:

- **Multicompany.**
  - A **SuperAdmin** works through the company switcher and can act on any
    company.
  - An **Admin** (AdminKT, AdminRG, AdminNE...) is locked to their own company.
    They can only see and change records whose `company_id` is their company.
  - Every query on company data must respect this.
- **Billing is read-only.** Never modify `KT-BILLING.LOCAL` or `dbbilling`. It
  is only a reference when porting logic into Murano.
- **Roles decide everything.** A user can do only what their role's permissions
  allow; the user type grants nothing, except SuperAdmin. A new feature adds
  its own permission checkbox.
- **Company profile.** A company must have its name, business type, owner,
  email, phone, address and helpdesk email (`App\Support\CompanyProfile`,
  Companies → Add / Edit).
- **SuperAdmin = the owner.**
  - The SuperAdmin role has every module by default.
  - `App\Support\SuperAdminAccess::sync()` runs after every `migrate`.
- **Online Store ≠ Website.**
  - The store and the company website have separate settings, menus and files.
  - Each website has its own address (`website_settings.url_path`).
  - Build website links with `App\Support\WebsitePaths::url()`.
  - An import first saves the current site
    (`App\Services\WebsiteSnapshots`, `files/company_{id}/website/snapshots/`).
- **Logins.**
  - Every employee gets a login automatically.
  - Users → Add creates only Admin / SuperAdmin logins.
  - A customer is identified by **email + company**.
- **Files** go to `public/files/company_{id}/{module}/`, through
  `App\Support\CompanyFiles`.
- **Roles.**
  - A user's role is stored in two places, `role_user` and `users.role_id`.
    Always set both.
  - A role can have a home page (`roles.home_path`). After login, the user
    lands there, or on the first page they are allowed to open.
- **Payroll** is weekly and is not filled from Attendance.
  - Hourly pay = completed service job hours × rate.
  - Commission = the employee's sales × %.
  - Payroll is posted to accounting: hourly to 7010, commission to 7040.
- **UI conventions.**
  - Dropdowns are sorted alphabetically with `sortByLabel()`.
  - Date range filters use `PresetRangeFilter`.
  - A link to another module's page uses `<PermLink>`. It is only clickable
    when the user's role can open that page.
  - A list with filters uses `useListState()` so Back restores it; forms
    opened from a list return with `goBack()` on Save / Cancel.
  - Sorting a list by a name column (Customer, Supplier, Warehouse...) goes
    through `App\Support\ListSort` on the server, never a raw `orderBy` on
    the column name the table sends.
- **Global processes are transactional.** An action that changes several
  things does all of it or none (e.g. removing an employee:
  `App\Services\EmployeeRemoval`; disabling a login: `App\Support\UserAccess`).
- **Public links** (booking codes, anything pasted outside the app) use
  `App\Support\PublicUrl`: the company's Public address, else the server
  address (`APP_URL`; production `https://murano.kcoltech.com`).
- **Sale numbers are per company**: the company's own prefix and its own
  highest number (Ruba `INV_3604` …).
- **Online booking**: only the name is required; customers get an email only
  when the booking is confirmed; limits per connection (5 per 10 minutes, an
  email check from the 4th in a day).
- **Network security** (per company, Settings → Network security): staff use
  the admin panel only from the company's networks or approved computers
  (`App\Support\NetworkAccess`, checked at sign-in and in `Is_Active`). Never
  the SuperAdmin, customers, suppliers, store, websites, portfolio, booking or
  portals.
- **PayPal** (Business Module, off by default; on for KcolTech): each company
  is paid into its own PayPal account (`PayPalService::forCompany()`); the
  "Pay now" page is a signed link built on the company's public address, and
  a payment is recorded once, in one transaction
  (`App\Services\PayPalInvoicePayment`).
- **Language follows the computer**: with no language picked, the login page,
  app, portal and PayPal page use the browser's language when it is English or
  Spanish, else the company's default. A pick (login EN / ES, the globe)
  always wins.
- **User manual.** Every change to a module, form, role or permission updates
  its entry in `resources/src/manual/manual.js`, in English **and** Spanish
  (header book icon). Role or company-module changes also refresh
  `KT-TOOLS/Manual/` (HTML + PDF).

---

## Latest changes

The last 5 sessions. The full history is in `kt_logs` and in the daily reports
in `KT-TOOLS/Report/`.

### 2026-10-19 (Thundera)

- **Only four protocols now: Wake-up, Pa Llevar, Copia BD and Kelsy.**
  - **Wake-up** (start of the day on any PC) replaces Preparate, Baja Prod and
    Sube Prod: update from GitHub, local backup, compare with production,
    bring production's new files and records down, offer to update production
    (backup + your OK), and a report with the current rules and the last 5
    changes.
  - **Pa Llevar** (end of the day) does the same production sync, then the
    manual, logs, backup, this README, commit and push (Sube Git is gone).
  - Backups are compressed, import as-is in phpMyAdmin, and go to
    `KT-TOOLS/database/<PC>/`, tagged wakeup / pallevar / copiabd.
- **PayPal module (per company; on for KcolTech only).**
  - Customers pay invoices into the company's own PayPal account with a "Pay
    now" link. Copy it from Sales › ⋮ › PayPal pay link, or put `{pay_link}`
    in the invoice email / SMS template.
  - The payment is recorded on the invoice by itself (method PayPal, into the
    chosen deposit account), never twice.
  - Settings › PayPal: Client ID / Secret (stored encrypted), Sandbox or
    Live, deposit account, optional webhook, and the list of PayPal payments.
    New permission "PayPal".
  - Ruba, New Era and Vanclem have it off.
- **Murano opens in the computer's language.** A computer set to Spanish
  (e.g. in Peru) sees the login page and the whole system in Spanish; any
  other computer sees English, or the company's language when the computer's
  isn't English or Spanish. The EN / ES switch on the login page (now
  working) and the globe in the app always win. The PayPal pay page and the
  customer portal follow the same rule.
- **Login page in Spanish.** The standard login texts now have Spanish
  ("Bienvenido de nuevo", "Ingresar"…). A company with its own login texts
  adds their Spanish in Settings › Login Page.
- **Manage Menu fix.** An Admin's reorder was refused ("Only a SuperAdmin can
  add or remove menu items") when Murano had a new built-in menu item; now
  only custom items count as added or removed.

Setup: `php artisan migrate`, `npm run build`.

### 2026-10-18 (Optimum)

- **Production ↔ dev sync.** Production owns the business data; dev's
  database changes travel only as migrations.
  - **Baja Prod** (new protocol, and Pa Llevar's first step every run) brings
    production down to dev: the files created there (`public/files`, over
    SSH) and the new / changed records. Nothing is deleted on either side.
  - **Sube Prod** ("actualiza producción") replaces the Hostinger Git deploy,
    which kept the project 4–5 times and ran the account out of files. From
    this PC over SSH it sends the code, the assets built here and the
    migrations (production backup first), keeps production's `.env`, and
    shows a maintenance page only while files change. It also sends the data
    created or changed in dev since the last Baja Prod, with conflict checks.
  - A role edited on one side keeps that side's permissions (no duplicates,
    nothing removed comes back).
  - Each machine needs its own `.env.production-db` / `.env.production-ssh`
    (not in git).
- **Business Modules (per company):** alphabetical list; **Real Estate** has
  its own switch (it followed the Store); new switches for Kitchen,
  QuickBooks, Xero, Google Sheets, Mailchimp, Slack, Telegram and PrestaShop.
  A module turned off also disappears from **Manage Menu**, and its items kept
  elsewhere in the menu (e.g. Hospital Reports under Reports) are hidden too.
- **ZATCA E-Invoicing (Saudi Arabia)** is now a module, **off by default** for
  every company: no ZATCA settings, QR or reporting unless turned on by hand.
- **Production outage fixed for good.** Hostinger was giving PHP old
  database settings that beat the server's `.env`. Now the `.env` always
  wins, and System Settings → Save no longer freezes the configuration in
  a cache.
- **Email Office assigns emails to customers by themselves**: sent ones also
  by CC, invoices / reminders to the sale's customer, and at once when a
  customer gets a new address. Assigning one email assigns every email with
  that address (received and sent).
- **New companies start with the SuperAdmin's menu order** (they used to get
  the factory order). Reminder: a menu change made as AdminSystem applies to
  every company.
- **The side menu is fully in the user's language**: renamed items
  ("Main Modules", "Access Management"…) and items whose translation key had
  another case (Deposit, SMS Templates…) are now translated.
- Files the site serves are no longer stored with Git LFS (the Hostinger
  deploy doesn't fetch LFS; a sale document arrived broken).

Setup: `php artisan migrate`, `npm run build`.

### 2026-10-17 (Optimum)

- **Sorting lists by name works again.** Sorting Sales (any filter, e.g.
  Unpaid, or All) by Customer or Warehouse showed an error. Fixed there and in
  Purchases, Quotations, Expenses, Damages, Adjustments, Sale / Purchase
  returns, Subscriptions, Shipments and online-store customer accounts.
- **Mail Settings → "Send a copy to other emails".** Each company can add
  extra addresses that get a hidden copy (BCC) of the emails it sends, and
  choose for which: **Sales** (invoices, payment receipts), **Payment
  reminders** and **Purchases** (orders, payments). Wrong addresses are
  refused when saving.
- **User manual → "Top bar icons".** Every top bar icon is shown with its own
  look and color, its name and what it does (company selector, POS, Settings,
  Install app, Como Vamos!, Full screen, manual, language, notifications,
  clock in / out, log out, your name), in English and Spanish. It opens under
  *This page* on the Dashboard and Profile, and is searchable.
- **New manual icon:** a colored open book, in the top bar and in the manual.
- **Protocols:** Sincroniza BD was removed; the new **Sube Git** is a quick
  push (logs, build, detailed README, commit, push) without the database
  backup of Pa Llevar.

Setup: `php artisan migrate`, `npm run build`.

### 2026-10-16 (Optimum)

- **Adding a product or service is guided** (Quick Add on the sale form and
  the full product form).
  - The main service you type is kept; it used to be cleared when moving to
    Price.
  - Choose the main item only, or a **new sub-service under it**, also under an
    existing main service.
  - "Will be saved as" shows the final name, following each company's naming
    style, and warns about duplicates.
- **Dev database brought up to date with production:** Ruba's 14 new sales
  (INV_3612–INV_3625), their accounting, stock, 6 emails and QuickBooks
  audit rows.
- **Production update SQL** for the Network security changes (phpMyAdmin-ready,
  tested) in `KT-TOOLS/SQL/`.

Setup: `npm run build`.

### 2026-10-16 (Thundera)

- **Network security** (Settings → Network security), per company, on for
  KcolTech: staff can use the admin panel only from the company's networks
  or from computers an admin approved. Store, websites, portfolio, online
  booking and the portals stay open; the SuperAdmin is never blocked.
  - Each person follows the company setting or their own (Restricted /
    Can sign in from anywhere).
  - Computers waiting for approval show in the bell; approve once, remove any
    time.
  - Managed by roles with the new "Network security" permission.
- The user manual (in-app and the overview / PDF) explains Network security.

Setup: `php artisan migrate`, `npm run build`.

---

## Session protocols

These are run from Claude Code. The definitions are in `.claude/commands/`.

| Protocol | What it does |
|----------|--------------|
| **Wake-up** | Start of the day on any PC. Updates the PC from GitHub (pull, installs and migrations only when needed, build), backs up the local database (`KT-TOOLS/database/<PC>/…_wakeup.zip`), compares production with this PC (code, migrations, files, records), brings production's new files and records down, offers to update production when this PC is newer (production backup + your OK), reloads the rules and the history, and opens a report with the current conventions and the last 5 changes (date + origin). |
| **Pa Llevar** | End of the day. The same production sync as Wake-up, then the user manual, build, logs, daily report, a local backup (`…_pallevar.zip`, also copied to `KT-TOOLS/SQL/` for the external backup program), this README, commit and push to GitHub. |
| **Copia BD** | A copy of the local database on demand (`KT-TOOLS/database/<PC>/…_copiabd.zip`): a main zip plus 3 Email Office zips. Every backup (dev and production) is compressed and imports as-is in phpMyAdmin. |
| **Kelsy** | Logs each finished request in `kt_logs`. This happens automatically after every request. |

Pushing uses GitHub Desktop when the terminal has no GitHub credentials. Never
force-push, and never commit `.env`.

---

## Documentation

- In the app: the header **book** icon (Murano's own manual, by module, EN / ES).
- `KT-TOOLS/Manual/murano_user_manual.html` / `.pdf`: Murano manual by company
  and role (PDF: `node KT-TOOLS/Manual/export_pdf.cjs`).
- `public/documentation/index.html`: the full user guide for the base product.
- `public/documentation/api/index.html` and `openapi.json`: the REST API
  reference, with about 1,200 endpoints.
- [CLAUDE.md](CLAUDE.md): the rules, conventions and history of this project.
- [CHANGELOG-STOCKY.md](CHANGELOG-STOCKY.md): the base product's vendor
  changelog.
