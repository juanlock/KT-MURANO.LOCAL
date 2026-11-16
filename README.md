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
`KT-TOOLS/Report/`).

### 2026-11-16 (Thundera)

- **KcolTech's new logo** (menu, PDFs, company card, website, forms).
- **Murano's own logo** on the login pages, the SuperAdmin (AdminSystem) and
  the user manual; the KcolTech logo stays KcolTech's only.
- **Murano flyer** (3 pages, Spanish) to offer Murano to any company: public
  link https://murano.kcoltech.com/murano-flyer.pdf; the SuperAdmin opens or
  copies it from the manual's header.

Already in production (deployed today with the user's yes).

Setup: `php artisan migrate`, `npm run build`.

### 2026-11-15 / 16 (Optimum)

- **Every sale keeps its invoice PDF** (all companies), made when it is
  created; a deleted sale's PDF is renamed "- Delete", never removed; a
  modified invoice keeps its earlier PDF as `-ver01-modified-<date>-<user>`.
  Every missing PDF was generated; Ruba's all start with `Invoice_INV_`.
- **Ruba:** test invoices removed; four deleted invoices are back as
  cancelled, ready to be activated (they have no lines).
- **KT-Log:** the closing message with its tables, titles in the request's
  language, a wider panel in 3 columns, attached files, filters in one line.
- **Change history** on the standard table (sort, column chooser, standard
  date filter), with code file and commit columns; global / SuperAdmin menu
  items on a light-yellow row.
- **KT-TOOLS** reaches every PC whole through Google Drive (backups, Temp,
  reports included) -- *not in production yet* (protocol scripts only).

Setup: `php artisan migrate`, `npm run build`.

### 2026-11-14 / 15 (Optimum)

- **A bus's Fuel tab** (Fleet -> vehicle): when the bus has no refuels, it
  lists the fuel of its trips (departure, route, km, fuel, per km) with the
  total and the average per trip; the vehicle's cost, distance and cost per km
  come from those trips. Notes in Spanish.
- **WhatsApp sign-in links work on the person's phone:** WhatsApp's link
  preview used to open the link first and take it ("this link does not work
  here"); now only a real browser claims it, entering by itself.
- **My trip on an iPhone:** an iPhone standing still gives no speed, so "I
  left" took long or never enabled; now it enables after the 30 s stopped and
  the page asks the phone for its position every 10 s. Tested with a real
  iPhone in Peru (left, arrived with photo and note).
- **Turismo:** owner flyer in production, demo crews remade (no pilot on two
  buses), presentation moved to Monday 2026-11-16.

Setup: `npm run build`.

### 2026-11-13 (Thundera)

- **Pilot alerts instead of a sum of hours** (Transport board, Fleet board):
  each pilot's alerts in the period -- more than 12 h in a row at the wheel,
  30 h in 2 days, 46 h in 3 days, no swap with the copilot on a trip over 6 h,
  two buses at once -- and a click shows why (dates, hours, trips). Limits per
  company (Manager / IT Admin).
- **Pilot swap on long trips:** "My trip" tells the pilot when to hand over the
  wheel and to whom ("Cambio hecho", bus stopped); the control board counts the
  late swaps and shows an icon per bus.
- **Fleet supervisor:** a new role and login with its own "Tablero de flota"
  (pilot alerts, control board, transport board) plus maintenance, parts,
  fuel and vehicles; no sales or profit.
- **Turismo's demo crews** are realistic: 65 pilots and 33 hostesses who rest
  between trips and are never on two buses at once.
- **Spanish everywhere:** the server's messages and validation errors follow
  the screen's language; meeting and asset emails go in the company's
  language; the last PDF labels are translated.
- **Dashboard (transport):** filters in a light bar, tabs as pills in the
  company's colour, all tabs in one row on a laptop; date buttons in one style
  (Hoy, Esta semana, Último mes...).
- **A company's own flyer and manual** open as a real PDF address with their
  name (no more "blob:"); Turismo's 15-page flyer for its Director is shown to
  the Manager, IT Admin and SuperAdmin only; the basic tourism manual was removed.

Setup: `php artisan migrate`, `npm run build`.
### 2026-11-12 (Optimum)

- **Turismo Cautivo, dashboard › Business summary:** a click (or double click)
  on any month of the chart opens "por qué este monto": a sentence with what
  happened and what moved it against the month before, income and costs by
  kind with ▲▼, the profit and its margin, trips with profit / loss and the
  profit of each route.
- **Business summary follows the dashboard's date filter:** the period's
  income, tickets, best-selling routes and fleet costs; the chart goes by day
  up to 62 days, else by month; "Todos" keeps the last 12 months; "today"
  stays today.
- **Dashboard › Indicators:** "A dónde se va el dinero" (it repeated the
  Expenses tab) is replaced by **"De dónde viene la ganancia"**: a donut with
  the profit in the middle and its two parts, tickets (what they leave after
  every trip cost) and parcels, with amount and %.

Setup: `npm run build`.
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
