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
  Support/               Helpers: CompanyFiles, EmployeeTabs, ServiceJobLinks, ServiceJobCost, ModuleFlags...
database/
  migrations/            Schema + data migrations (run on every machine)
  scripts/               Protocol scripts (backups, reports)
resources/src/           Vue 3 SPA
  pages/                 One folder per module
  components/  lib/      Shared UI and helpers (PresetRangeFilter, sortByLabel, goBack...)
  config/                Menu, permissions, dashboard sections
public/files/company_{id}/{module}/   Uploaded / generated files, per company
public/documentation/    Stocky's user guide + REST API reference (OpenAPI)
KT-TOOLS/                Backups (database/, SQL/), reports (Report/), tools
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

---

## Latest changes

The last 5 sessions. The full history is in `kt_logs` and in the daily reports
in `KT-TOOLS/Report/`.

### 2026-10-06 (Thundera)

- **Service jobs and sales.**
  - Service jobs are linked to customers and invoices.
  - The sale form has **Add / Edit Service Job** and **Add / Edit Contract
    Sale** buttons, which open modals.
  - You are warned when a job is already on another invoice.
  - Each linked job's status can be changed, with a note.
- **Service job pages.**
  - The job form has a **Task** tab.
  - The jobs list has an **Assign to** column with an "All operators" (crew)
    option, and PDF / Excel export.
  - "Service Technicians" are now called **Operators**.
- **Tasks page** (Week view by default, plus Day and Month).
  - Ruba roles SupervisorRG and OperatorRG, with role home pages.
  - Users without the dashboard no longer see "Page not available".
- **Weekly payroll** (by hours or by commission), posted to accounting.
  - Job costing: labor + equipment, with a Cost / Invoice / Margin view.
  - Demo payroll history and demo holidays for every company.
  - Ruba's shift is Monday–Friday, 7 AM – 5 PM.
- **Assets** are on for every company, with demo sets per business type
  (landscaping, salon, IT, office, …).
- **New Era roles** AdminNE, ManagerNE and StaffNE.
  - Only managers open / close the register; staff sell with their PIN.
  - A **Clock in / out** button in the header.
  - Attendance is company-scoped.
- **Interface.**
  - Back navigation returns to the previous list.
  - Header has a Log out button and colored buttons.
  - The login page offers only English / Spanish, with flags.
  - The mobile left-margin gap is fixed.
  - Ruba's duplicate landscape product groups were merged.

Setup: `php artisan migrate`, `npm run build`, `php artisan optimize:clear`.

---

## Session protocols

These are run from Claude Code. The definitions are in `.claude/commands/`.

| Protocol | What it does |
|----------|--------------|
| **Preparate** | Start of session. Syncs the PC (pull, migrate, build) and reloads the rules. It also reads the request history (`kt_logs`) and writes status reports to `KT-TOOLS/Report/`. |
| **Kelsy** | Logs each finished request in `kt_logs`. This happens automatically after every request. |
| **Copia BD** | Backs up `dbmurano` with mysqldump into `KT-TOOLS/database/`. It writes a main zip plus 3 Email Office zips, all importable in phpMyAdmin. |
| **Pa Llevar** | End of session. Builds, logs, writes the daily report, backs up the database, updates this README (Latest changes), then commits and pushes to GitHub. |

Pushing uses GitHub Desktop when the terminal has no GitHub credentials. Never
force-push, and never commit `.env`.

---

## Documentation

- `public/documentation/index.html`: the full user guide for the base product.
- `public/documentation/api/index.html` and `openapi.json`: the REST API
  reference, with about 1,200 endpoints.
- [CLAUDE.md](CLAUDE.md): the rules, conventions and history of this project.
- [CHANGELOG-STOCKY.md](CHANGELOG-STOCKY.md): the base product's vendor
  changelog.
