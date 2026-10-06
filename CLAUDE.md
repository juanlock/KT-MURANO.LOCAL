# KT-Murano — Project Context

This file is auto-loaded by Claude Code at the start of every session in this
repo, and it's tracked in git so it stays in sync across all machines used to
work on this project. Treat everything below as standing context — no need to
re-explain it in future sessions.

## Protocol: "preparate"

Trigger phrases: **"jarvis run protocolo preparate"** / **"protocolo preparate"**.
When the user says this, it refers to the rules and definitions documented in
this file (this section and the ones below). Re-read this file to refresh
context when invoked.

Also available as a slash command (`.claude/commands/preparate.md`), so it's
usable the same way on every machine this repo is checked out on: it first
syncs the current PC to the latest pushed version (git pull -- falling back
to what GitHub Desktop last fetched when the session has no GitHub
credentials -- then `composer install` / `npm ci` / `php artisan migrate`
only when the incoming changes need them, `php artisan optimize:clear`, and
always `npm run build`, since `public/js` isn't in git), then reloads
this file's rules, reads the `kt_logs` table (the history of every
request/order made to modify this project) so prior work and decisions are
known before anything is changed, digests that history into a memory file and
surfaces the most recurrent changes (the former Mañanitas), then closes out by generating and opening a small HTML
status report (`database/scripts/protocol_preparate_report.php`, written to
`KT-TOOLS/Report/kt_murano_preparate_<date>.html`) showing the **last 2** `kt_logs`
requests -- index table, then grouped by module (from the files each touched),
each with date requested, the verbatim request, the solution (closing message),
what changed and the files affected -- and each one's **status**
(Completed / In Progress / Blocked -- see the `status` column note in
`.claude/commands/kelsy.md`), plus the full-history activity report
(`database/scripts/protocol_memorex_report.php` ->
`KT-TOOLS/Report/kt_murano_memorex_<date>.html`, the former Memorex).
**Mañanitas and Memorex were merged into Preparate and their command files
deleted (user's request, 2026-10-04)** -- "protocol Mañanitas" / "protocol
Memorex" now mean the matching steps of Preparate.

**Protocol reports are kept and shared (user's rule, 2026-09-30):** every
HTML report a protocol (or an ad-hoc "report for the last N requests")
generates goes to **`KT-TOOLS/Report/`** -- git-tracked, committed by Pa
Llevar, so every programmer gets them. One file per day per report
(`kt_murano_preparate_<date>`, `kt_murano_memorex_<date>`,
`kt_murano_last<N>_<date>`, and Pa Llevar's `kt_murano_daily_<date>` with all
of that day's requests); a re-run the same day overwrites that day's file,
older days are never deleted. Not `.claude/report/` or a temp dir.

**Glossary — "multicompany"**: when the user uses this word, it refers to the
rule spelled out below in "Roles & Multicompany Model" -- the system is
prepared/built to serve more than one company from a single install. In
short: a SuperAdmin gets a company dropdown/selector on company-scoped forms
and can switch/modify which company's data they're viewing or editing; an
Admin (AdminKT, AdminRG, etc.) is fixed to their own company and never sees
that selector -- only a SuperAdmin can change the acting company.

## Roles & Multicompany Model

- **SuperAdmin** — a user (or users) with user type `Superadmin` in the users
  table. No restrictions, full permissions, role = owner. Can view / edit /
  delete any form in the system. On forms that are company-scoped, a SuperAdmin
  sees a company dropdown/selector and the form's data/behavior follows the
  selected company.
- **AdminRG** — a user type `Admin`, restricted/limited according to their
  member role, tied to a specific company (e.g. member of company 2). Example
  pairing: `AdminKT` ↔ company 1, `AdminRG` ↔ company 2.
- **Multicompany rule (applies system-wide)**:
  - Logged in as **SuperAdmin** → view/edit forms show a company dropdown to
    select which company's records to operate on.
  - Logged in as **Admin** (e.g. AdminKT, AdminRG) → can only read/edit/delete
    records where `company_id` matches their own company. No company selector.

## Systems

- **Billing** (aka the "older system") — lives at `KT-BILLING.LOCAL`, database
  `dbbilling`. This is the current system in production use.
  **Read-only for us: never modify billing's code or database.**
- **Murano** (aka "the new system") — lives at `KT-MURANO.LOCAL` (this repo),
  database `dbmurano`. New code, new features/modules.

**App timezone: `America/New_York`** (`.env`'s `APP_TIMEZONE`, deliberately
set — the business runs on Eastern time; this used to default to `UTC`,
which was wrong, not intentional). Don't "fix" this back to `UTC` in a
future session — `UTC` was the bug. This is a real Settings page feature
already (Settings updates `APP_TIMEZONE` in `.env` + `artisan config:clear`,
`SettingsController.php` around the `timezone`/`APP_TIMEZONE` keys), not
something invented ad hoc. Since `now()`/`Carbon` write literal (timezone-naive
`DATETIME`) values to the DB, changing this shifts what gets stored for
*new* rows going forward — existing historical timestamps were written under
the old `UTC` setting and were not retroactively converted.

## Base Product & Documentation

Murano is built on **Stocky** (a commercial CodeCanyon Laravel product,
currently v5.8) — a single-business POS/inventory/online-store/accounting/HRM
system. This explains the ~294 unrelated Models and the Hospital/School/MRP/
Fleet/etc. modules seen throughout the codebase: they're stock Stocky
features, not anything built for this project specifically.

**Full user-facing documentation is at `public/documentation/index.html`**
(read directly off disk, not a URL — ~2,500 lines covering every feature
area: install, settings, products, POS, sales, purchasing, the online store,
accounting, HRM, every business module, integrations, reports). There's also
a full REST API reference at `public/documentation/api/index.html` with an
OpenAPI 3 spec at `public/documentation/api/openapi.json` (~9 MB, ~1,200
endpoints) — too large to read wholesale, but worth grepping/fetching a
specific path from when a task needs to know an endpoint's exact shape.

**Important architectural fact from that documentation, stated explicitly:**
*"No multi-tenant or SaaS mode. One install is one business, with as many
warehouses, users and stores as you like."* Stocky's native segmentation unit
is the **warehouse** (physical stock location within one business), not a
"company." The SuperAdmin/AdminKT/AdminRG multicompany model above is
**custom work layered on top of this base product** — Stocky was never
designed for it — which is why it needs its own `company_id` scoping
(`ScopesToCompany`, `resolveActingCompanyId()`) built out module by module
rather than being a built-in concept.

## Migration Approach

- Murano was not built with a dynamic menu — its menu is currently static.
  The work is to adapt/port the menu logic from billing so Murano's menu
  behaves the same way (dynamic, like billing).
- **Direction of adaptation**: keep Murano's new code as the base; adapt/port
  billing's tables and code *into* Murano to match — not the other way around.
- **Scope of migration** — only these modules move from billing → murano:
  1. Sales
  2. Company
  3. Menu (make it dynamic, matching billing's behavior)
  4. Employees
  5. Products
  6. Translations
  7. kt_log

## Development Standards

Applies every time a module is **created or modified** (Preparate reloads it at
the start of each session).

### File uploads (user's rule, 2026-10-04)

- **Every file a module stores** -- uploads, imported email attachments,
  generated documents -- goes to **`public/files/company_{id}/{module}/`**
  (e.g. `files/company_2/helpdesk/2026/10/…`, `files/company_2/email-office/…`,
  `files/company_1/products/…`). One folder per company, one sub-folder per
  module. Never `storage/app`, never a shared folder for the whole install.
- Use **`App\Support\CompanyFiles`**: `dir($companyId, $module)`,
  `putUpload(...)` / `putBytes(...)` (adds `{Y}/{m}/{random}-{name}`),
  `owns($path, $companyId, $module)` before serving, `download($path, $name)`.
  Store the path relative to `public/` in the DB (`files/company_…`).
- **Private modules** (bills, attachments, contracts, anything nobody should
  open by URL) pass `$private = true`: the module folder gets a `.htaccess`
  that blocks direct web access, and files are served only through an
  authorized controller (permission + same company + `owns()`).
- **Only a SuperAdmin, for install-wide/global settings** (system logo,
  defaults), may write elsewhere under `public/` (e.g. `public/images/`).
  Company Admins never write outside their own `files/company_{id}/`.
- Company folders are git-tracked like the rest of `public/files` (they travel
  between machines with Pa Llevar) -- keep big imports in mind.

### People, logins and permissions (user's rules, 2026-10-05 / 2026-10-06)

- **Every employee has a login.** An employee always has its `users` row
  (type `employee`, `employees.user_id`), created automatically -- never "No
  user is linked to this employee". The `Employee` model's `created` hook
  provisions it through `App\Services\LinkedUserService::provision()` for any
  path (form, import, script); the login is active only when the company has a
  default employee password (Defaults -> Users), else disabled until System
  access sets one. Never insert employees with `DB::table('employees')->insert()`
  (it skips the hook) -- use the model, or provision the login yourself.
  Customers, suppliers and sales agents likewise get their login from their own
  module (`LinkedUserService`).
- **A customer is identified by email + company** (user's rule, 2026-10-01).
  Automatic matching (online booking, imports, linked users) = same email in
  the same company only, otherwise a new customer; never match by phone, never
  across companies, never attach to the Walk-in customer; ask before merging.
  Only exception (2026-10-05): Zelle deposit emails carry just the payer's name,
  so there the same exact name in the same company = same customer.
- **Users -> Add creates Admin / SuperAdmin logins only** (SuperAdmin only by a
  SuperAdmin). Employee / customer / supplier / sales-agent logins are created
  in HRM -> Employees, People -> Customers / Suppliers, Commissions -> Sales
  Agents (the server refuses them in Users -> Add). Users is where any login is
  **edited** (role, locations, password, PIN) and **disabled** (Active /
  Disabled switch -- never your own account).
- **The role is mandatory.** A user can do only what their role allows
  (`role_user` -> permissions); no permission is granted by user type
  (SuperAdmin aside). Example: Email Office follows the role's
  `email_office_view` / `email_office_manage` checkboxes, Admins included.
  New features add their own permission checkbox (`resources/src/config/permissions.js`
  + a `permissions` row) instead of a type check.
- **Employee details: View / Edit per tab** (`employee_tab_{tab}_{view|edit}`,
  Roles -> HRM -> Employees; edit needs view), enforced server-side by
  `App\Support\EmployeeTabs` (also the company + own-record boundary for the
  endpoints behind the tabs). A new tab on that page gets its own pair of
  permissions.

## Frontend Conventions

(Reloaded by Preparate step 1, like the Development Standards.)

- **Dropdowns are alphabetically sorted by default.** Any `<a-select>`/combobox
  option list (Vue `computed` building `{value, label}` options) must be
  sorted alphabetically by its displayed label, using the shared
  `sortByLabel()` helper (`resources/src/lib/filterOptions.js`) -- wrap the
  options array in it rather than writing an ad-hoc `.sort()`. Apply this
  when creating a NEW dropdown, not just when fixing an existing one.
  Exceptions need a deliberate reason (e.g. a "Manage Menu" admin-defined
  order, or a list where recency/relevance genuinely matters more than
  alphabetical) -- default to alphabetical unless there's a reason not to.
- **Date range filters: our own filter is the standard (user's rule,
  2026-10-05).** Every list / report / dashboard that filters by a date range
  uses Murano's date filter -- the shared preset buttons (All, Today, This
  Week ... Last Year, `lib/datePresets.js`; double-click = the user's default
  ★, `lib/datePresetDefault.js`; dropdown on phones) plus the editable
  **Start date ... to ... End date** fields -- never a bare `<a-range-picker>`
  or two separate From / To `<a-date-picker>`s, and never a new ad-hoc
  filter. Use, in this order:
  - **`PresetRangeFilter`** (`resources/src/components/PresetRangeFilter.vue`)
    -- drop-in for `<a-range-picker v-model:value="range"
    value-format="YYYY-MM-DD" @change="reload">` (`allow-all`, `compact`,
    `apply-default` props). The default for any new page.
  - **`DateRangePicker`** -- reports that keep dayjs values or need
    `show-time` (it renders PresetRangeFilter, plain picker only with time).
  - **`DatePresetButtons` + `DateRangeFields`** -- only for pages that keep
    their own preset key / `applyPreset()` (Sales, Purchases, Customers, POS
    Sales, Bookings, Payments report, Dashboard).
  Applies when creating a NEW page/filter and when touching an old one (swap
  it). Date *fields* where data is typed in (a contract's start/end, a leave,
  a holiday) are not filters and keep `<a-date-picker>`.

## Session Protocols (ported from KT-BILLING.LOCAL)

The `kt_log` module migration (see above) included porting these Claude Code
slash-command protocols from billing into this repo, adapted for `dbmurano`
and this repo's own `kt_logs` migration (`database/migrations/2026_09_22_120000_create_kt_logs_table.php`,
model `app/Models/KtLog.php`). Definitions live in `.claude/commands/`:

- **Kelsy** (`/kelsy`, or "Claude/Jarvis, run protocol Kelsy") — append this
  conversation's activity to `kt_logs`.
  **Standing rule (user's request, 2026-09-30): log every request right away.**
  As soon as a request is finished -- code change, question, check, anything
  -- append its `kt_logs` row immediately (same column rules as Kelsy,
  including the verbatim closing message in `done`), without waiting for
  Kelsy / Pa Llevar to be invoked and without asking first. Those protocols
  then only have to catch up anything that slipped through. Location comes
  from the machine (`KT-THUNDERA` -> Thundera, `KTOPTIMUM_NB` -> Optimum,
  Home PC -> Home); ask only if
  it's genuinely unclear.
- **Preparate** (`/preparate`, or "Jarvis, run protocolo preparate") — not
  ported from billing, this one is Murano's own: syncs this PC to the latest
  version (pull + only-if-needed install/migrate + `npm run build`), reloads
  this file's rules, reads `kt_logs`, digests it into a memory file and
  surfaces recurring change patterns, then closes out with an HTML status
  card for the last 2 requests plus the full-history activity report (see
  above). The start-of-session counterpart of Pa Llevar. **Mañanitas and
  Memorex (both ported from billing) were merged into it and their
  `/mananitas` / `/memorex` commands deleted (user's request, 2026-10-04).**
- **Copia BD** (`/copia-bd`, or "Jarvis, run protocolo copia bd") — not
  ported from billing, this one is Murano's own: dumps `dbmurano` via
  `mysqldump` (`database/scripts/protocol_copia_bd.php`) into
  `KT-TOOLS/database/dbmurano_<date>_<host>_<time>.zip` (e.g.
  `dbmurano_2026-09-29_KT-THUNDERA_11-23-38-AM.zip` — **split in two and
  zipped since 2026-10-05 (user's rule)**: the main zip has every table except
  the Email Office ones, and the Email Office tables (`office_emails`,
  `email_office_settings`, rules / folders / payees -- ~130 MB of imported
  mail that pushed the single `.sql` past GitHub's 100 MB limit) go in
  **three files since 2026-10-06 (user's rule)**: `..._office_emails_1/_2/_3.zip`,
  `office_emails` cut in three parts of about the same size (file 1 also has
  the tables' structure, file 3 the triggers); restore = unzip all, import the
  main one, then office_emails 1, 2, 3 in order — host from
  `gethostname()`, time human-readable 12-hour with AM/PM via `now()`, not a
  bare digit run) — a phpMyAdmin-independent backup after a local phpMyAdmin
  export bug corrupted a manual backup attempt. Every dump is phpMyAdmin-ready
  (imports as-is into any selected database, e.g. Hostinger): all CREATE TABLE
  first, then data, then triggers; no CREATE DATABASE / USE / LOCK TABLES /
  DEFINER; portable utf8mb4_unicode_ci collations. `KT-TOOLS/` is git-tracked,
  so the snapshot travels across every machine this checkout is on, same as
  the other protocols.
- **Pa Llevar** (`/pa-llevar`, or "Jarvis, run protocolo pa llevar") —
  Murano's own end-of-session close-out (A Dormir was merged into it and
  then deleted, user's request 2026-10-01): checks `npm run build`, runs
  Kelsy, writes the day's report (`KT-TOOLS/Report/kt_murano_daily_<date>.html`),
  then Copia BD (+ copies to `KT-TOOLS/SQL/backup db.zip` and `backup db office emails 1/2/3.zip`), then commits everything with a summary subject and a
  detailed description of the changes (one bullet per `kt_logs` request,
  migrations, setup steps for other machines, backup file) and pushes to
  GitHub (never force-push; without CLI credentials the user pushes from
  GitHub Desktop). It syncs the user's machines through GitHub -- it is NOT a
  production deploy.
Billing's originals stay untouched (billing is read-only, per the Hard Rules
below) — these are Murano's own copies, kept in sync manually if the protocol
logic evolves.

## Hard Rules

1. Never modify billing's code or `dbbilling` — read-only source of truth for
   porting logic/data structure only.
2. Murano's own new code/architecture is kept; billing is adapted to fit it,
   not the reverse.
3. Every form/query touching company-scoped data must respect the
   SuperAdmin-vs-Admin multicompany rule above.
