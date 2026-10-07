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
  **The SuperAdmin is the system's owner: access to every module by default
  (user's rule, 2026-10-06).** Its role (`role_user` -> e.g. "Owner") always
  holds every permission except the `menu_hide:*` ones:
  `App\Support\SuperAdminAccess::sync()` runs after every `php artisan migrate`
  (AppServiceProvider, `MigrationsEnded`), so a new module's permission reaches
  it automatically. (Before this, the Owner role was missing
  `portfolio_manage` and the Portfolio menu was invisible to the SuperAdmin.)
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
- **Role home page** (2026-10-06): `roles.home_path` (Roles -> "Home page")
  sends that role's users to a page instead of the dashboard (Home and the
  dashboard redirect there). Field roles use **Daily Tasks** (`/service/daily`,
  `DailyTasksController`): `daily_tasks` = own jobs (the service technician
  linked to my employee), start / complete with a note; `daily_tasks_all` =
  everyone's; `daily_tasks_assign` = assign the technician. Ruba: SupervisorRG
  (manager) and OperatorRG (field employees).
- **Payroll is weekly, by hours or commission** (2026-10-06): employees.pay_type
  (`hourly` = hours on the service jobs they COMPLETE that week (`work_hours`,
  asked on Complete in Daily Tasks / the job's Task tab) x `hourly_rate`;
  `commission` = their sales that week (stylist on the sale's booking, else the
  sale's sales agent linked to their user) x `commission_rate` %), from
  `pay_effective_from`. HRM -> Payroll -> "Generate weekly payroll"
  (`App\Services\PayrollRun`); paid payrolls post to accounting via
  `PayrollExpenseSync` (Salaries and wages 7010 / Commissions 7040). Owners'
  own employee records (admin login) stay off payroll. Job cost = labor (hours
  x operator rate) + equipment used (`service_job_assets`, asset `hourly_cost`)
  -- `App\Support\ServiceJobCost`. Rates: Ruba manager $25 / operators $20,
  KcolTech $35, New Era 40% commission.
- **Employee details: View / Edit per tab** (`employee_tab_{tab}_{view|edit}`,
  Roles -> HRM -> Employees; edit needs view), enforced server-side by
  `App\Support\EmployeeTabs` (also the company + own-record boundary for the
  endpoints behind the tabs). A new tab on that page gets its own pair of
  permissions.

### Online Store vs Website Management (user's rule, 2026-10-06)

- Two separate modules, each with its own menus, options and files:
  **Online Store** (the shop: store_settings, files/company_{id}/store/) and
  **Website Management** (the company website: `App\Models\WebsiteSetting`,
  files/company_{id}/website/ -- own name, logo, favicon, SEO, top bar/footer,
  social links, menus). **Imported websites live in
  `files/company_{id}/website/sites/{name}/`** (`SiteImporter`; moved out of
  `store/sites/` on 2026-10-07 -- nothing of the website goes in `store/`).
  Their pages are edited in Website -> Pages (`SitePageEditor`): texts,
  pictures, links and, since 2026-10-07, **Sections** -- the blocks the menu
  jumps to (`#x` -> `id="x"` or class `x`) and blocks with an embed, edited as
  HTML (+ "Use our booking calendar" = `/book?company={id}&embed=1`). Company
  admins can't add scripts / event handlers there; only a SuperAdmin can. Same settings page (`StoreSettings.vue`), scope from
  route meta (`/store/settings` vs `/website/settings`).
- Website pages (front page with our website theme, CMS pages, gallery,
  contact) render with `WebsiteSetting::applyTo()` and `$website = true` (no
  cart / search / shop category bar / shop sign-in); shop pages keep the
  store's settings. `StoreFrontController::website()`.
- **Each website has its own address**, never `/online_store?company=ID`:
  `website_settings.url_path` (Website settings -> Branding -> Website URL,
  e.g. `/kcoltech`, `/kcoltech/pages/{slug}`, `/gallery`, `/contact`; route
  groups per path in `routes/web.php`, `WebsiteCompany` middleware). Build
  website links with `App\Support\WebsitePaths::url($rel, $companyId)`; old
  `?company=` links 301 to the website address. New companies get a path from
  their name (`WebsitePaths::suggest`), system first segments are refused.
- **An import never loses the current website** (2026-10-06): before
  Front page -> Import replaces the imported site, `App\Services\WebsiteSnapshots::take()`
  copies it to `files/company_{id}/website/snapshots/{stamp}/` (table
  `website_snapshots`); Front page -> "Saved websites" lists them (Preview /
  Use this / Delete).
- **Theme-only tabs** (user's rule, 2026-10-06): Hero, SEO, Top bar & footer
  and Social links show only when the front page uses our website theme; with
  an imported website they are hidden (it brings its own tags, header, footer
  and social icons). Website settings then shows Front page, Homepage blocks
  and Branding (Website URL, name, logo).
- **Gallery** and **Portfolio** are their own modules: Gallery (`/gallery`,
  files/company_{id}/gallery/) and ONE Portfolio (the Portfolio module -- the
  old store_portfolio_* tables were merged into it; the website's Portfolio
  section reads `portfolio_projects`).

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
- **Links to another module's page respect the role** (2026-10-06): use
  `<PermLink :to="..." permission="Sales_view">` (global component,
  `components/PermLink.vue`) instead of a bare `<router-link>` -- a user whose
  role can't open the target sees plain text, never a link to "no access".
  Use the target route's `meta.permission`.
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
  then Copia BD (+ copies to `KT-TOOLS/SQL/backup db.zip` and `backup db office emails 1/2/3.zip`), then updates
  `README.md` (a "Latest changes" block for the session -- last 5 kept -- and any section that changed; user's rule
  2026-10-06), then commits everything with a summary subject and a
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
