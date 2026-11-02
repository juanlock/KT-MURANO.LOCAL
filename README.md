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
  - The SuperAdmin role has every module by default.
  - `App\Support\SuperAdminAccess::sync()` runs after every `migrate`.
  - Business Modules switches never hide anything from the SuperAdmin; they
    apply to the company's own users.
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

### 2026-11-01 (Optimum) -- not in production yet

- **Transport module** (Turismo Cautivo and any transport / tour company):
  agencies (one per city, e.g. LIMA, CHICLAYO, CHEPEN, TRUJILLO) and the
  terminals of each agency, routes with km and hours, trips (one bus, 2 pilots,
  1-2 hostesses, never double-booked), active staff by type, crew paid per
  trip in payroll, fleet maintenance in house or at a workshop (supplier).
- **Transport board** at the top of the main dashboard: agencies, terminals,
  fleet, staff, trips and km; a map with the agencies, the routes and the
  buses on the road; **Fleet now** (buses at an agency / in transit / in
  maintenance, current trips with their crew); pilots' driving hours against
  the safe limit, the record one in red; a card per agency.
- **Transport demo** for any tourism company (uses the company's own agencies
  and routes when it has them); applied to Turismo Cautivo on this PC only.
- Main dashboard: the **Shortcuts** section is now at the end of the page.
- Claude Code reminds the working conventions on every message (answer in
  the user's language, kt_logs...).

Setup: `php artisan migrate`, `npm run build`. Turismo's demo data is NOT in
production (live company); after Wake-up on another PC it can be applied again
in dev with the demo runner.

### 2026-10-27 / 2026-11-01 (Optimum)

- **Marketing never emails another company's customers again.** A campaign
  reaches only its own company's customers, through that company's own mail
  server; a company with Marketing switched off never sends (demo companies
  have it off); test addresses are skipped. SuperAdmin can delete any
  company's campaign from AdminSystem.
- **Roles, one group per company**: Admin Compañía, Helpdesk, Website,
  Formularios (read only), Customer and Employee, plus the business's own
  roles (hospital, school, manager / field roles). Old Owner / AdminRG /
  AdminNE / Admin-ALL / Info roles are merged; New Era keeps a single admin.
- **Forms**:
  - The public form can have its own header (company or own logo, color band,
    centered), width, button above or below, a message and then another page
    after N seconds; fields without a label show no empty line and required
    errors turn the field red. Form codes for websites are always https.
  - Submissions open in a large window with Previous / Next, Print, PDF, Send
    by email and **Attended** with a note; only Manage forms can delete.
  - Dashboard: submissions per form, Read / Unread (clickable to the report)
    and a table grouping a form's answers (e.g. complaints by type or agency).
- **POS**: its settings are a section of Settings, and a new option chooses
  which roles and users may open / close the register (the POS says who can).
- **Menu**: the main dashboard is "Tablero Principal" in Spanish. Imported
  websites get their menu in Spanish on their Spanish pages (KcolTech).
- **Manual**: updated (new Forms entry), a **Your company** tab with the
  advantages for the company's type, a PDF manual and a **2-page flyer** per
  business type and language; SuperAdmin's **Company manuals** page shows them.

Setup: `php artisan migrate`, `npm run build`.

### 2026-10-27 (Thundera)

- **Login security, for every way in** (staff login, customer portal, mobile
  app):
  - **Locked after 5 wrong passwords** in an hour, for 1 hour, from any
    computer. The person and the company's admins get an email; an admin
    unlocks it in Settings -> Login security or in Users ("Locked until").
    Resetting the password by email unlocks it too.
  - **Standard passwords must be changed** at the first sign-in (Empleado2020,
    Cliente2024, C0ntr0l123...), except the demo logins.
  - **Password rules:** at least 8 characters with letters and numbers, not a
    standard password, not the email, not one of the last 3.
  - **Two-step verification:** each company picks which user types need a code
    after the password (Settings -> Login security); anyone can turn it on in
    Profile. The code arrives by email, or comes from an authenticator app
    (QR + recovery codes). "Remember this computer" skips it for 30 days.
  - **Sign-in alerts:** an email when a login is used from a new computer.
  - Settings -> Login security also shows the latest sign-in activity.
- **Login devices** (AdminSystem): Company and Role columns, and the usual
  filters (dates, company, role, type, search); the filters sit beside the
  table's search (any list can now put its filters there).
- **Production server**: a daily backup of the database on the server
  (outside the website, 14 kept), a morning email to the SuperAdmin with the
  previous day's errors, daily log files, and the scheduled tasks work on
  Hostinger (it blocks starting processes; they now run inside the scheduler).
  The 3,583 jobs stuck in the queue since September (integrations no company
  uses) were removed and those integrations no longer queue anything.
- **Each company's online store is its own**: settings, payments, warehouses
  and emails of the store the visitor opened (they used the first company's).
- **Mobile app**: the server is ready for the two-step code and the password
  change in the app (the app needs its screens).
- **Backups** live in Google Drive (`Versions\database`, one folder per PC,
  production, HOSTINGER); only the latest copy stays in the repo.
- **User manual** updated (sign-in, two-step, Login security, specialist
  clinic, arrival scans, companies in production), in-app and the PDF.
- Billing is turned off by hand on 2026-10-28: Ruba and New Era work in Murano
  only from then on.

Setup: `php artisan migrate`, `npm install`, `npm run build`.

### 2026-10-26 (Thundera)

- **Companies in production** (Companies -> Edit -> Website, AdminSystem only):
  Ruba, New Era and Turismo Cautivo are marked; production owns their data.
  Pa Llevar now also sends the day's dev data to production -- never anything
  of those companies -- and a new row whose number production already used
  goes up with a new one. New companies Turismo Cautivo and Centro de
  Especialista Arce (with demo data) are in production.
- **New company type: Health Specialist** (outpatient specialist clinic, on the
  hospital data). Reception and schedule (doctor columns, drag & drop, check-in
  with payment / insurance, late-doctor alert icon), nursing station (vitals,
  "ready to go in" tells the doctor), the specialist's EMR (problem list, SOAP
  with dictation, templates for general medicine, pediatrics with growth chart,
  ophthalmology, cardiology, gynecology, psychology, psychiatry, prescriptions,
  lab / imaging orders, referrals, follow-ups), 48 h email reminder with
  Confirm / Can't come, NPS survey, clinic reports, and the patient portal
  (book, pre-registration, prescriptions). Roles: Reception, Clinic Nurse,
  Specialist, Billing.
- **Arrival scans**: at the clinic check-in and the hospital admission window
  the desk scans / photographs the insurance card and the ID (front and back);
  private files, never a payment card.
- **Removing a doctor** (HRM → Employees): off the schedule and the portal, their
  upcoming appointments go to "To schedule" (patients emailed), inpatients
  without attending doctor show in the Control tower, roster cleared.
- **Multicompany documents**: invoices, purchases, quotations, returns, payments,
  receipts, SMS / WhatsApp / emails and reference prefixes now use each
  company's own name, logo, currency and footer (they used the first company's).
  The currency symbol follows the company everywhere.
- **Emails**: the subject replaces its {tags} too; {invoice_url} is now a
  "View invoice" link to the public signed invoice (which works again, the QR
  of the receipts too).
- **HRM in the company's language**: every dropdown (employment type, gender,
  marital status, pay, leaves), the employee tabs, form and printout; default
  HR names created in the company's language. Company pickers only show other
  companies to the SuperAdmin.
- **KT-Log** (Settings, SuperAdmin on AdminSystem only): the work history with
  our filters and the closing message of each request.
- Header shows the company logo and name when the side menu is hidden; login
  chooser cards fixed (and an expired page returns to a fresh chooser);
  "How are we doing" only for roles with the profit report; demo data: save the
  business type on its own, Commissions / Store no longer blocked by false "real
  data"; crop / resize for the System Settings logo.
- Setup: `php artisan migrate`, `npm run build`.

### 2026-10-25 (Optimum)

- **Hospital, the whole stay.**
  - **Configuration**: shifts with their hours, floors, rooms and beds, and a
    map per floor; a freed bed goes to cleaning first.
  - **Admission window + Triage**: the window registers the patient, the
    insurance or card (only its last 4 digits) and why they come; emergencies
    wait for the **triage nurse** (new role and screen: call the patient,
    vital signs, weight, height, level 1–5, send to an ER bed); critical
    patients go to a bed first and are registered after; referred patients
    (e.g. surgery) wait for a bed. Without insurance or a card an emergency
    patient is not admitted to a ward.
  - **Stay**: administrative file and account (insurer / patient split, DRG),
    the clinical chart (Kardex with wristband scan, progress notes, vitals,
    care orders, interconsultations, discharge plan, epicrisis), the
    **Control tower** and **My round** (the doctor's / nurse's patients on the
    floor plan with the route and a visit window).
  - **Doses**: only today's can be recorded; unrecorded past ones become "not
    given". **At discharge** a stay with insurance and / or a card is billed
    and paid automatically.
  - **Lab** critical values and image files; monthly / semester **shift
    planning** with rotation; **patient portal** ("My hospital": status of the
    day, diet, visit hours, discharge instructions, videos, appointments, 24 h
    email reminders).
  - Hospital demos stay alive every day (today's roster, patients assigned,
    example stays); Vitalis has triage and inpatient examples.
- **Logins.** Every company gets standard logins ({company}-admin / -helpdesk
  / -info) and default passwords per type in new companies and demos.
  **Company logins** (profile menu, the company's admin): every login with its
  function, role and accesses, the password when still the standard one, and
  **Enter** to work as that user (back with "Back to admin").
- **Customer portal on by default** for every customer of every company.
- **Dates** follow the company's country (Peru DD-MM-YYYY, USA MM-DD-YYYY) and
  every date field can be typed.
- **Tablet catalogs** (menu / models with photos), Business Modules reset by
  business type, a copy of modules and menu from another company
  (SuperAdmin), and the new login screen.
- **Protocols**: Pa Llevar now deploys without asking and shuts the PC down
  when everything went well; new **Restaura BD Dev** (restore this PC's dev
  database from a backup).
- **After the deploy**: the hospital demo staff, beds and stays exist only in
  the developer database; regenerate the hospital demo of Vitalis in
  production (System Settings › Demo data, SuperAdmin) to have them there.

Setup: `php artisan migrate`, `npm run build`.

---

## Session protocols

These are run from Claude Code. The definitions are in `.claude/commands/`.

| Protocol | What it does |
|----------|--------------|
| **Wake-up** | Start of the day on any PC. Updates the PC from GitHub (installs only when needed), replaces the local database with a copy of production (`php artisan murano:from-prod`, about a minute, then the local migrations), builds, offers to deploy this PC's newer code (your OK), reloads the rules and the history, and sums up in the chat with the last 5 changes. |
| **Pa Llevar** | End of the day. Build, logs, a local backup (`…_pallevar.zip`, also copied to `KT-TOOLS/SQL/` for the external backup program), this README, commit, push, then the code deploy to production **without asking** (production is backed up first) and the dev data, never the companies in production, and, when every step went well, the PC shuts down (2 minutes, `shutdown /a` cancels). The user manual only when asked. |
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
