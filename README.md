# KT-Murano

KT-Murano ("the new system") is a multi-company business platform: POS, sales,
purchasing, inventory, service jobs, bookings, HRM, accounting, Email Office,
portfolio sites and more. It replaces the older **KT-Billing** system, which is
still in production.

One install serves several companies (KT, Ruba, NewEra, ...). Each company sees
and edits only its own data.

> Murano is a **KcolTech** product. © 2026 KcolTech. All rights reserved.
> Proprietary software: see the License entry of the in-app manual.

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
`KT-TOOLS/Report/`, in Google Drive).

### 2026-11-20 (Thundera)

- **New module "Distribución B2B" and the company HORDEX EIRL** (migrated from its
  earlier system, in production): Inicio, Clientes, Proveedores, Productos, Compras,
  Facturas, Guías, Kardex, Control de pagos and Reportes, laid out like its earlier
  screens (list on the left, form / detail on the right, a click on a row shows it).
  Guías: "XML" button reads the SUNAT electronic guide. Facturas: the invoice
  document (number box, state, amount in words, the chain's withholdings) with
  Anular / Imprimir / PDF; default payment term "FACTURA 30 DIAS". Reportes: five
  Excel exports. Only its own modules are on.
- **Side menu pin** (icons only, submenus on hover); a company can start pinned.
- **Tables:** compact rows by default, a stronger row highlight, click on a row
  to open its detail (where a page uses it).
- **Install the app on this computer** (account menu): installs as
  "Murano - <company>" with the company's logo, and that computer is trusted.
- **Sign-in:** a login can ask an emailed code from every new place (IP).
- **Users:** "email already taken" only when creating a login or changing its
  email; a customer login of another company never blocks a staff login.
- **Company logins:** disabled logins hidden, with a "Mostrar desactivados" button.
- **Como Vamos!:** reloads on every open (it kept the previous company), only the
  company's attendance / opening balances, stock valued on positive quantities,
  no false profit when products have no cost.
- **Clock in / out icon** only when the company has the HRM module.
- **Projects:** timeline tab, tabs reordered and more visible.
- **Dev PCs stay in sync** with each other (protocols), never touching production.

Setup: `php artisan migrate`, `npm run build`.

### 2026-11-19 (Optimum)

- **Projects work like Asana:** each project opens as its own workspace with
  tabs -- Overview (progress, open / late tasks, milestones, hours vs
  estimate, billable value, coming up, team), List, Board, **Calendar** (new:
  task due dates, milestones, project start / end), Milestones, Hours and
  Report. New tasks from a project stay in it. The menu keeps only Dashboard
  and Projects; each tab follows the role's permission.
- **Projects multicompany:** the dashboard, tasks, milestones, hours and
  reports showed every company's projects; now each company sees its own
  (AdminSystem all). Creating a project or a task no longer fails (optional
  employee, priority "Medium" by default, priority / hours / progress saved).
- **Projects in the selected language:** every screen of the module in
  Spanish / English.
- **Settings -> Customize:** the logo's real colours, a bigger custom colour
  with its hex code, 36 preset colours. **KcolTech's logo** without its dark
  background.
- **Turismo:** agencies always in the order of the line (Lima, Trujillo,
  Chepén, Chiclayo) in every list, filter and chart.
- **KT-TOOLS** mirrored to Google Drive and OneDrive at the end of Pa Llevar /
  A Dormir; Wake-up only reads (Excel lock files skipped).

Setup: `php artisan migrate`, `npm run build`.
### 2026-11-18 (Thundera)

- **Turismo, "Cómo funciona" (presentation):** Manual → Su empresa → "Cómo
  funciona (presentación)": who takes part (sales, operation and maintenance
  around the live-data panel), the services table (tickets and parcels: sold,
  run, quality checked), operation and maintenance; full screen with the
  arrow keys.
- **Quality control (Control de Calidad):** a new role whose home is the
  complaints board (its own page, without the owner's money) and the forms;
  Turismo has it (its access login and Daniel). Every company will get it.
- **Turismo demo:** eight months of history, and the demo buses on the road
  leave a GPS trail on the control board.
- **Business summary on a 1366x768 laptop:** the agency table no longer
  overlaps and the chart fits the screen.
- **Admins and the SuperAdmin are never signed out for inactivity.**
- **Call center demo (Alo Mundo):** the real leads show 6 rows at most and
  can't be copied; the floor indicators have colours.
- **Backups never hold up the work:** the backup before writing to production
  is made on the server in seconds; bringing it to Drive waits for the next
  Pa Llevar / A Dormir.
- **Turismo after the presentation:** Daniel's role is now **Operaciones**
  (agency supervisor + fleet supervisor + quality control in one); new roles
  **Administración y Contabilidad** (Mayte) and **Agente de agencia** (the one
  person of Chepén / Trujillo); one pilot per trip (a copilot only over 12 h),
  no hostesses, no snacks; each ticket has its sales channel (agency, Jelaf,
  PeruBus, phone); the dashboard opens on the departures report, then the new
  **Mando operativo** (upcoming departures red / amber / green against their
  costs, and which week marketing should push; also in the menu).
- **KcolTech:** its 41 services show up on quotations and sales again; the
  quotation PDF looks like the invoice (no product code line); a sale prefix
  typed with its dash (PP-) keeps its series, and today's two invoices were
  renumbered PP-0529 / PP-0530.

Setup: `php artisan migrate`, `npm run build`.

**Later the same night (not in production yet):**
- **Turismo, "Mi parada":** the home screen of the one person of a
  pass-through agency (Chepén, Trujillo): the next buses with their expected
  time and GPS, who gets off and on (a no-show frees the seat for the rest of
  the trip), last-minute passengers sold on the agent's open cash register
  (no sale without it), parcels loaded / unloaded, "bus arrived" and "bus
  dispatched".
- **KT-TOOLS:** each company's material lives in `KT-TOOLS/files/<company>`;
  the sync moves an old root folder (turismo, ARCE, kcoltech) there by itself.
- **KT-TOOLS in two clouds, like git:** Pa Llevar / A Dormir end by making
  Google Drive and OneDrive exactly equal to the PC's KT-TOOLS (what was
  deleted on the PC is deleted there too); Wake-up only reads them and makes
  the PC equal to the newest complete push. OneDrive is found from the PC's
  OneDrive folder (`D:\NetLock\OneDrive\...` on Thundera and Optimum).

Setup: `php artisan migrate`, `npm run build`.
### 2026-11-17 (Optimum)

- **Ready-made websites become the company's own:** Website → Settings →
  Front page now always shows the gallery of 31 ready-made websites (it was
  hidden behind "import from an address"); using one puts the company's logo
  (also logos drawn by the stylesheet), name, e-mail and phone in its copy.
- **Their contact forms work:** a ready-made website with a contact form
  switches the Forms module on, creates the company's "Contáctenos" form and
  connects it, so the visitors' messages arrive in Forms (22 of the 31 have one).
- **No Shop / Cart / My Account bar** on a website whose company has no online
  store.
- **"Quotations" was translated as "Citas"** in Spanish: now "Cotizaciones".
- **Alo Mundo (call center demo):** website with a ready-made design, only the
  call center modules on, and a 4-page call center flyer (access levels of the
  supervisor and the agent, security) in the manual's book.

Setup: `php artisan migrate`, `npm run build`.

### 2026-11-17 (Thundera)

- **New module: Call center** (off by default; on for call center companies,
  e.g. Alo Mundo): campaigns that pick their leads by age, sex, location and
  any imported column; the agent's screen "Mi campaña" (each lead goes to one
  agent only, results, callbacks, pauses); leads with an Excel / CSV import
  that matches the columns on screen and skips only the bad rows; call log;
  agents' shifts with lateness and absences; floor indicators live; quality
  reviews; billing per campaign to the client as a sale; telephone exchange
  (click to call + the exchange sends each call).
- **Alo Mundo** shows only its call center modules; demo with a supervisor and
  3 agents (`php artisan callcenter:demo --company=1016`).
- **KT-TOOLS is out of git:** the whole folder travels between the PCs only
  through Google Drive (Wake-up / Pa Llevar / A Dormir sync it).

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
- [CLAUDE.md](CLAUDE.md): the rules, conventions and history of this project.
