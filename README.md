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

### 2026-11-12 (Thundera)

- **Sign-in link by WhatsApp** (Company logins › Link): staff who don't use
  passwords (pilots, hostesses) get a link that signs them in without one,
  only on the first phone that opens it; it can be renewed or removed.
- **Change history** (Settings › Historial de cambios, SuperAdmin only): who
  changed what, when and from where, before and after; the last 6 months,
  older changes kept in an archive, never deleted.
- **Turismo's demo** has six months of history, with ticket income per
  departure close to the client's real report; its hourly routine went from
  11 minutes to 1.5 seconds.
- **Sales › Tax to pay** card: the sales tax of the filtered invoices, with
  what was collected and what is pending (Ruba).

Setup: `php artisan migrate`, `npm run build`.
### 2026-11-11 (Optimum)

- **Daily departures:** "All agencies" shows every agency's day in one table
  with a card per agency (open / closed, PDF); **History** is now a toggle in
  the same table (60 days back and a week ahead, the day in words and a line
  between days); buttons for the previous / next day and week and **Today**.
- **"Mi agencia"**, the agency supervisor's new home: summary of today and the
  period, parcels, complaints and incidents of their agency only (see and
  create, with a photo), and **seats used per trip by bus** against the
  minimum each trip needs to cover its costs (green / orange / red).
- **Dashboard in the owner's reading order**, opening on **Business summary**
  (now with the last 12 months of income, costs and profit, and no date
  filter there); new **Expenses** tab (by kind, trend, against the period
  before); the **Departures report** shows every agency and never a day ahead;
  no "General" tab.
- **Company logins:** a row of buttons by role, and every function in Spanish.
- **Forms:** a **Tab** element, and progress that can be saved
  (**Save** / **Save and send**, a draft shows as "Borrador" in Forms).
  Turismo's questionnaire is one link with 20 questions in 4 tabs coded by
  who answers (DUE owner, GER, SUP, FLO).
- **Dates** on transport screens follow the company's format and every date
  filter uses the standard preset buttons.
- **Turismo's demo** covers two months and a week ahead with the client's own
  daily plan for all four agencies (2 demo buses complete their fleet of 19),
  crew, punctuality, incidents, services paid and parcels near S/ 250,000 a month.

Setup: `php artisan migrate`, `npm run build`.
### 2026-11-10 (Thundera)

- **Daily departures** (transport): each agency's day replaces the client's
  Excel; the day is copied from an earlier one, passengers and amounts come
  from the POS tickets, and **Close day** keeps the totals, makes a PDF and
  emails it. A closed day can only be reopened by IT Admin / Manager.
- **Collapsing the side menu moves it to the top bar**: its groups become
  buttons with their items in a popup, the bar takes the company's colour and
  the company logo replaces the person's picture.
- **No language button in the top bar** (it repeated Settings / Profile), and
  **the alerts bell is each company's choice**, off by default: System
  Settings › General › Show alerts, then which alerts.
- **POS "Open register"** answers at once on a developer PC (it waited 16 s
  for the notice email).
- **Forms dashboard in tabs**: Summary, Analysis (the complaints book grouped,
  by agency and route), Submissions and Forms; each fits one screen.
- **Murano is KcolTech's**: the old base's name is gone from every screen and
  file, the updater no longer asks the old vendor's server, the footer shows
  Murano's version, and the manual has a **License** entry.
- **The unused phone app support was removed** (its API, its settings page
  and its push notices); phones use the web app.
- **Developer PCs never email real people**: everything goes to one address.

Setup: `php artisan migrate`, `npm run build`.
### 2026-11-09 (Thundera)

- **Turismo Cautivo's guide for its meeting** (9 pages, Spanish, with its fleet's
  photos and charts from its own data) is kept outside git, with the client's material.
- **A company's own manual in the book icon:** the company tab shows a
  "Manual de {company} (PDF)" button when that company has its own manual
  (today Turismo Cautivo). It is a private file, and only roles with the
  dashboard permission can open it. The PDF has to be copied to production by
  hand, because Turismo's files never travel with Pa Llevar.
- **The in-app manual** has new entries for passenger transport (tickets,
  boarding, parcels, supervisor, My trip, monitoring, setup, dashboard) and
  for fleet and workshop. Role names are updated to IT Admin, Manager and Info.
- **The overview manual by company** has its tourism section rewritten with its
  7 roles and a transport guide.

Setup: `npm run build`.

### 2026-11-09 (Optimum)

- **Turismo Cautivo, dashboard › Complaints:** the reasons are grouped in a
  few groups (tickets and refunds, the bus and its condition, staff, luggage
  and parcels, boarding, times, safety). The "Otros reclamos" (44 % of all)
  are sorted by the words of what the customer wrote (broken seat, bugs on the
  seats, a different bus from the one bought, date change...); hovering a bar
  shows its detail. The customer's text is never shown.
- **New dashboard tab › Parcels:** one button per agency; cards with
  received, attended, pending today (and late), income against the previous
  period and against the client's figure (S/ 250,000 a month), average parcel,
  income per kg and complaints; charts by route, by agency, pending by
  destination, income by day, parcels by size (by weight) and by type, and the
  complaints about parcels.

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
