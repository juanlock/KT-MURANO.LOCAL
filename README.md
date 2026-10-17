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
  Support/               Helpers: CompanyFiles, EmployeeTabs, ServiceJobLinks, ServiceJobCost, ModuleFlags, WebsitePaths, SuperAdminAccess, CompanyProfile...
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
- **User manual.** Every change to a module, form, role or permission updates
  its entry in `resources/src/manual/manual.js`, in English **and** Spanish
  (header book icon). Role or company-module changes also refresh
  `KT-TOOLS/Manual/` (HTML + PDF).

---

## Latest changes

The last 5 sessions. The full history is in `kt_logs` and in the daily reports
in `KT-TOOLS/Report/`.

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
- **Sincroniza BD** protocol: compares production with dev (structure,
  migrations, data), shows the differences, backs production up and applies
  only what you approve -- or writes SQL files to import by hand.
- The user manual (in-app and the overview / PDF) explains Network security.

Setup: `php artisan migrate`, `npm run build`.

### 2026-10-13 (Thundera)

- **Lists come back as you left them.** Sales, Purchases, Quotations,
  Customers and Suppliers keep their filters, dates, page and search when you
  return with Back, the browser's back arrow, or Cancel / Save in the edit
  form. Opening a list from the menu starts fresh.
- **Duplicate invoice** works for paid invoices (and invoices with a return):
  it only needs the "add sale" permission.
- **Edit Sale shows which invoice is open**: "Edit Sale · INV_…", customer,
  date, total, paid and due, status tags and View invoice. A duplicate says
  which invoice it copies.
- **Save messages name the invoice** ("INV_3609 created."), with a warning
  when the invoice date isn't today (it won't be in the Sales list on Today).
- **Product tax.** New products start at 0% (they used to copy the company's
  Default Tax, which is the invoice's Order Tax, so the line was taxed
  twice). Tax per product / service still works as before.
- **Sale form columns** follow Invoice PDF → Default → "Discount column" /
  "Tax column": hidden when off, shown by themselves when a line has a
  discount or its own tax.
- **New services get the unit "UND"** (they used to have none and showed
  "---" in Stock); editing a service keeps its unit.

Setup: `npm run build`.

### 2026-10-12 (Optimum)

- **Users list** has filters: Type, Role (or "No role") and Status.
- **Group Permissions** shows how many users have each role. Click the number
  to open Users filtered by that role.
- **Users → Edit** shows the linked employee (department, position, shift,
  pay) with a link to HRM → Employees.
- **Companies → Add / Edit** ask for everything the system needs, in steps
  (Company, Helpdesk, Defaults, Admin user, Website).
  - Required: owner name, business type, address and helpdesk email.
  - Defaults: currency, language, sale prefix, public address and the default
    passwords.
  - A **Setup** column shows what each company is still missing.
- **Clear demo** now names the record that blocks it. Company 1003's 2 test
  sales were removed, and that company was cleared and deleted.
- **Production update SQL files** (dev → production, tested before use) are
  in `KT-TOOLS/SQL/`. Run part 1, then part 2.

Setup: `php artisan migrate`, `npm run build`.
### 2026-10-09 (Optimum)

- **Profile → Header icons.**
  - Show is green and Hide is red.
  - The company's setting under each icon is shown in green or red.
  - Each user can change the order of the icons in the top bar with ‹ ›
    arrows, or put the standard order back with **Reset order**.
- The user manual (English and Spanish) explains both.

Setup: `npm run build`.

---

## Session protocols

These are run from Claude Code. The definitions are in `.claude/commands/`.

| Protocol | What it does |
|----------|--------------|
| **Preparate** | Start of session. Syncs the PC (pull, migrate, build) and reloads the rules. It also reads the request history (`kt_logs`) and writes status reports to `KT-TOOLS/Report/`. |
| **Kelsy** | Logs each finished request in `kt_logs`. This happens automatically after every request. |
| **Copia BD** | Backs up `dbmurano` with mysqldump into `KT-TOOLS/database/`. It writes a main zip plus 3 Email Office zips, all importable in phpMyAdmin. |
| **Sincroniza BD** | Compares the production database with dev (structure, migrations, data) and shows the differences. Only with your approval: backs production up, then applies the approved tables, or writes SQL files to import by hand. Production access lives only in the git-ignored `.env.production-db`. |
| **Pa Llevar** | End of session. Updates the user manual with the session's changes, builds, logs, writes the daily report, backs up the database, updates this README (Latest changes), then commits and pushes to GitHub. |

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
