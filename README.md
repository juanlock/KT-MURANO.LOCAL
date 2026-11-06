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
  - **superadmin/AdminSystem** (company 999 picked) sees every company's
    records in every list and report (with a Company column) and has the
    global screens (yellow, "Global": Login Page, Update, Currencies, Backup,
    System Health, Login Devices; route / menu `systemOnly`).
    **superadmin/<company>** works on that company only. Lists read their
    company with `ScopesToCompany::listCompanyId()` (null = every company);
    writes keep `resolveActingCompanyId()`.
  - An **Admin** (AdminKT, AdminRG, AdminNE...) is locked to their own company.
    They can only see and change records whose `company_id` is their company.
  - Every query on company data must respect this. Modules that were shared
    (Hospital, School) filter with `App\Models\Concerns\BelongsToActingCompany`.
- **Production is the only real database.** Dev is a disposable copy
  (`php artisan murano:from-prod`); every database change a feature needs is a
  migration, safe to run twice, by name not by id. Other dev data goes up at
  Pa Llevar, **never for the companies in production** (Ruba, New Era,
  Turismo Cautivo; Companies -> Edit -> "Company in production"); a row whose
  id production already used goes up with a new id.
- **Settings a company manages** are limited (`App\Support\SettingsAccess`);
  mail, payment methods, SMS templates, PayPal, ZATCA, currencies and
  integrations are the SuperAdmin's. Every company uses AdminSystem's mail
  server (`Server::deployTemplate()`).
- **Billing is read-only.** Never modify `KT-BILLING.LOCAL` or `dbbilling`. It
  is only a reference when porting logic into Murano.
- **Roles decide everything.** A user can do only what their role's permissions
  allow; the user type grants nothing, except SuperAdmin. A new feature adds
  its own permission checkbox.
- **Company profile.** A company must have its name, business type, owner,
  email, phone, address and helpdesk email (`App\Support\CompanyProfile`,
  Companies → Add / Edit).
- **SuperAdmin = the owner.**
  - A SuperAdmin is the user type; its role, **SuperAdmin** (ex Owner), lives
    only in AdminSystem and has every permission
    (`App\Support\SuperAdminAccess::sync()` runs after every `migrate`).
  - With a company picked, the SuperAdmin sees that company's modules and menu
    as its admin does; with AdminSystem picked, every module.
- **Roles of every company** (`App\Support\CompanyRoles`): Admin Compañía (the
  company's IT person, `{company}-admin@`), Manager (runs the business, no
  configuration), Info (forms, read only), Helpdesk, Website, Employee,
  Customer, plus the business's own job roles. Admin / SuperAdmin logins are
  never an employee, customer or supplier; every other login is.
- **Business types** (`App\Support\BusinessTypes`): a new company's modules,
  accounts, expense categories and demo data follow its type; it starts from
  the AdminSystem company (999: menu, settings, roles, language, currency).
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
- **Language**: inside the app, the user's own pick, then the company's
  language. Before sign-in (login page, portal, PayPal page) the browser's
  language when it is English or Spanish, else the company's; the login EN / ES
  switch wins there.
- **User manual.** Every change to a module, form, role or permission updates
  its entry in `resources/src/manual/manual.js`, in English **and** Spanish
  (header book icon). Role or company-module changes also refresh
  `KT-TOOLS/Manual/` (HTML + PDF).

---

## Latest changes

The last 5 sessions. The full history is in `kt_logs` (older daily reports in
`KT-TOOLS/Report/`).

### 2026-11-05 (Home) (not in production yet)

- **Turismo Cautivo's dashboard opens on the departures report**, and the
  pilots' red line is gone from the transport board (their hour bars stay).
- **Screens in the company's language**: about 870 texts that were written by
  hand in English now have their Spanish (fleet, email office, contracts,
  imports, products, customers, payroll, POS and detail screens). English
  companies keep reading English. Email and SMS templates, server messages and
  PDFs are still to review.
- **Turismo's invoices** no longer carry another company's US note (late fee,
  phone numbers); terms and the ticket's closing line are in Spanish, and its
  walk-in customer is now "Cliente de mostrador".
- **Product forms offer only the company's own warehouses** (they listed every
  company's). The same pattern is still to fix in other modules.

Setup:    ERROR  Command "migrate" is not defined. Did you mean one of these?  

  ⇂ migrate  
  ⇂ migrate:fresh  
  ⇂ migrate:install  
  ⇂ migrate:refresh  
  ⇂ migrate:reset  
  ⇂ migrate:rollback  
  ⇂ migrate:status  , .

### 2026-11-03 (Optimum)

- **POS ticket sales (transport)**: selling a route ticket asks for each
  passenger: departure (the route's scheduled trips), identity document type
  and number (DNI, CE, passport...), adult / minor / infant, names and luggage;
  a known document fills the names. The price list selector is hidden for
  tickets; the POS buttons and close-out speak the company's language. Buses
  have their photos on the ticket products.
- **Forms submissions**: a **Reply** button emails the sender (their message
  quoted below) and marks it attended; the list adds Agency, Destination and
  Email columns (DNI instead of the long document name); the window repeats
  the form's layout (sections, half / full widths) compactly.
- **Company logins**: a **Change** button sets a login's password from the
  table (standard / generated / copy, ask to change at next sign-in).
- **Account menu** (top right): the person's card -- name, email, username,
  role, user type, department / job and locations; picture or name open Profile.
- **Each role opens its own screen**: ticket office -> Terminal POS (POS),
  pilot / hostess -> their own trips, mechanic -> fleet maintenance, Info ->
  forms; a role without a home page opens the intranet. On the POS lock, the
  "Dashboard" button of a POS-only role goes to its sales.
- **Faster local backups** and production migrations run on the server.

Setup: `php artisan migrate`, `npm run build`.

### 2026-11-03 (Home)

- **Every role of every company has a user.** The automatic roles (Supplier,
  Sales Agent, Online Store) exist in every company, a fifth access login
  `{company}-website@kcoltech.com` (Website role) is added, and each people
  role nobody held gets a demo record with its login (Cliente Demo, Empleado
  Demo, Proveedor Demo, Agente de Ventas Demo, Cliente Tienda Demo). Companies ›
  Add does the same; Settings › Roles by company shows 0 for a role without
  users.
- **Companies › Rename** also renames the new logins (and their records'
  emails), and older admin usernames such as "adminarce".
- **Companies list**: new **Business type** column (sortable, exported).
- **Ruba Garden's Contact Us form** now lives in Murano (Forms): name, email,
  phone, subject, message, notices to info@; its website (home page and
  Contact Us) shows it instead of the old external form.
- **Transport agencies are Locations.** Each agency has its own sales, cash
  registers and users; its ticket office sells on the POS with a **Terminal
  POS** login assigned to that agency only. The Manager and the IT Admin see
  every agency. The tourism business type now has POS and no Portfolio /
  Website / Gallery.
- **Turismo Cautivo presentation demo** (in production): buses, crew, trips,
  route tickets (S/ 20-85), a cashier per agency, passengers and 30 days of
  ticket sales, kept current every hour.
- **Demo data fits the business**: a bus company no longer gets delivery vans,
  IT portfolio works or generic shop pages.

Setup: `php artisan migrate`, `npm run build`.

### 2026-11-02 (Optimum) -- in production since 2026-11-03

- **IT Admin**: the role "Admin Compañía" is now called **IT Admin** in every
  company (same permissions and users).
- **A fourth standard login per company**, `{company}-manager@kcoltech.com`
  with the **Manager** role, in every company that had no manager yet (Turismo
  Cautivo included; Ruba and New Era keep their own managers).
- **Companies › Rename** (SuperAdmin): changes a company's name everywhere in
  one step, with a preview; the website and store name and the helpdesk sender
  are offered, the website address and the standard logins' emails are
  optional.
- The Spanish menu says **Usuarios** (was "Los usuarios").
- **Database backups take seconds** (Copia BD / Wake-up / Pa Llevar / A Dormir):
  about 12 seconds instead of 9 minutes; the files are built on the local disk
  and only the finished zips go to Google Drive.
- **Website for Dr. David Arce** (SanaMente Perú, Chulucanas) in
  `KT-TOOLS/ARCE`: a one-page site with Conocer Chulucanas, his medical
  services and his publications (stories, press notes, videos, each with its
  source link); what the client still has to send is in its PENDIENTES list.

Setup: `php artisan migrate`, `npm run build`.

### 2026-11-02 (Home)

- **Roles redone for every company.** New **Manager** role: the business's
  main modules without settings, users, roles or HR setup (Ruba's
  info@, New Era's info@ / admin@ / Monica / Silvia). **Info** (ex
  "Formularios") only reads the forms. New Era: a single new company admin
  login, its stylists on **Staff**; Ruba keeps Supervisor / Operator. The
  SuperAdmin's role is now called **SuperAdmin** (ex Owner) and a new
  SuperAdmin is always created in AdminSystem with it.
- **Admins are only admins.** An admin or SuperAdmin login is never an
  employee, customer or supplier: the 17 admin employee records were removed
  and new companies no longer create one; giving a linked login the Admin
  Compañía role asks first (remove the record, or keep it as it is).
- **Settings › Roles by company** (SuperAdmin, AdminSystem): every company and
  every role with its users.
- **SuperAdmin sees a company as its admin does** (its modules and menu order)
  when that company is picked; the login chooser offers only each company's
  admin (Info, Helpdesk... are entered from Company logins).
- **Manage Menu** saves by itself on every change (AdminSystem keeps Save with
  its confirmation), drag only drops between rows, "Move to…" picks the place
  and the position; the empty "Access Management" group is gone.
- **Demos kept current every day** (Settings › Demo Data › "Update the demo
  every day"): hospital roster and stays, school register up to yesterday,
  transport trips a week ahead with buses on the road.
- Vitalis's doctors now have their employee, login and Doctor role.
- POS entries are hidden when a company's POS module is off.

Setup: `php artisan migrate`, `npm run build`.

---

## Session protocols

These are run from Claude Code. The definitions are in `.claude/commands/`.

| Protocol | What it does |
|----------|--------------|
| **Wake-up** | Start of the day on any PC. Updates the PC from GitHub (installs only when needed), replaces the local database with a copy of production (`php artisan murano:from-prod`, about a minute, then the local migrations), builds, offers to deploy this PC's newer code (your OK), reloads the rules and the history, and sums up in the chat with the last 5 changes. |
| **Pa Llevar** | End of the day. Build, logs, a local backup (`…_pallevar.zip`, also copied to `KT-TOOLS/SQL/` for the external backup program), this README, commit, push, then the code deploy to production **without asking** (production is backed up first) and the dev data, never the companies in production. The PC is never shut down by a protocol (only when asked at that moment). The user manual only when asked. |
| **A Dormir** | End of the day without touching production (work not ready yet): local backup, a production backup into `<archive>/HOSTINGER/` (not in git), logs, README, commit and push. |
| **Copia BD** | A copy of the local database on demand (`<archive>/<PC>/…_copiabd.zip`): a main zip plus 3 Email Office zips. Every backup (dev and production) is compressed and imports as-is in phpMyAdmin. |
| **Restaura BD Dev** | Restores THIS PC's dev database from a backup: shows the latest one and asks first; the current database is backed up before (`…_prerestore.zip`). Never production. The usual way to align a PC is still Wake-up. |
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
