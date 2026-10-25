# KT-Murano — Project Context

This file is auto-loaded by Claude Code at the start of every session in this
repo, and it's tracked in git so it stays in sync across all machines used to
work on this project. Treat everything below as standing context — no need to
re-explain it in future sessions.

## Protocols: Wake-up, Pa Llevar, A Dormir, Copia BD, Kelsy (user's rules, 2026-10-19 / 2026-10-20)

Only four protocols (`.claude/commands/`), no repeated orders between them:
**Production is the only real database (user's rule, 2026-10-20).** A
developer PC's `dbmurano` is a disposable copy of production, replaced every
morning by `php artisan murano:from-prod` (~1 min: `mysqldump | gzip` on the
server over SSH, import, production's new files, local migrations). Code goes
up (git -> `protocol_prod_deploy.php`, with the user's yes); schema and the
data a feature needs go as migrations; **dev data never goes up** (test data
is gone the next morning). `kt_logs` lives in production
(`database/scripts/protocol_kt_log.php`). The row-by-row
`protocol_prod_pull.php` / `protocol_prod_push.php` are only for an
exceptional case.

- **Wake-up** (`/wake-up`, "Jarvis, wake up" / "protocolo wake-up") -- the
  start of the day on any PC: git pull (+ composer / npm ci only when
  needed), `php artisan murano:from-prod`, `npm run build`, an offer to deploy
  this PC's newer code (the user's yes), this file and the `kt_logs` history
  reloaded (memory digest), and a summary **in the chat** with the **last 5
  changes** (date and origin). "Preparate" / "buenos días, prepárate" mean
  Wake-up.
- **Pa Llevar** -- the end of the day: an offer to deploy the code (no data
  goes up), manual, build, Kelsy, a local backup tagged `pallevar`, README,
  commit, push.
- **A Dormir** (`/a-dormir`, "Jarvis, a dormir", 2026-10-20) -- the end of the
  day WITHOUT touching production, for work not ready / not tested yet: local
  dev backup tagged `adormir` (PC folder + a copy in the root of
  `KT-TOOLS/database/`, the root's older copy moved to its PC folder), a
  production backup (`php artisan murano:prod-backup` ->
  `KT-TOOLS/database/HOSTINGER/hostinger-<date>_<time>-<PC>-a-dormir.zip`,
  git-ignored: customer data), Kelsy, README (block marked "not in production
  yet"), commit, push. No deploy, no migrations or data to production.
- **Copia BD** -- a database copy on demand, tagged `copiabd`.
- **Kelsy** -- the `kt_logs` logging rules; rows are written to production.
Every protocol starts by running `hostname` (never asks the user): the PC name
for file names and the Kelsy location (`KT-THUNDERA` -> Thundera,
`KTOPTIMUM_NB` -> Optimum, any other -> Home).
Every database backup is compressed and phpMyAdmin-ready
(`database/scripts/protocol_sql_clean.php`): local ones in
**`KT-TOOLS/database/<PC name>/dbmurano_<date>_<time>_<wakeup|pallevar|copiabd>.zip`**
(+ `_office_emails_1/_2/_3`), production ones in `KT-TOOLS/database/production/`
(git-ignored), made before anything is written to production. Every local
backup also tidies `KT-TOOLS/database/` (a stray backup goes to its PC's folder)
and leaves a **copy of the latest backup in its root**
(`dbmurano_<date>_<PC>_<time>_<tag>...zip`, only the latest one;
`protocol_backup_tidy.php`; user's rule, 2026-10-19).
**No HTML reports from the protocols (user's rule, 2026-10-19, to save
resources):** Wake-up and Pa Llevar report in the chat only. `KT-TOOLS/Report/`
keeps the reports already made (never deleted); a report file is written only
when the user asks for one ("report for the last N requests").

**Glossary — "multicompany"**: when the user uses this word, it refers to the
rule spelled out below in "Roles & Multicompany Model" -- the system is
prepared/built to serve more than one company from a single install. In
short: a SuperAdmin gets a company dropdown/selector on company-scoped forms
and can switch/modify which company's data they're viewing or editing; an
Admin (AdminKT, AdminRG, etc.) is fixed to their own company and never sees
that selector -- only a SuperAdmin can change the acting company.

**Glossary — "superadmin/<company>"** (user's convention, 2026-10-21): e.g.
"superadmin/bicentenario" = the user is signed in to Murano in a browser as
the SuperAdmin and picked that company (Colegio Bicentenario) in the top-bar
company dropdown, so the changes are made as the SuperAdmin acting on that
company. (Entering "as the company admin" from the login chooser is a
different thing: that is "admin/<company>".)
**superadmin/AdminSystem vs superadmin/<company>** (user's rule, 2026-10-21):
superadmin/AdminSystem (company 999 picked) has every menu and form PLUS the
global ones (effect on the whole install), which show in the side menu in
yellow with a "Global" tag: Login Page Settings, Update Settings, Currencies,
Backup, System Health, Login Device Management (menu / route `systemOnly`,
router guard + `isMenuEntryAllowed`), plus Backup archives / Login devices in
System Settings and the full Companies list with Add / Delete
(`CompanyController::isSystemContext`). superadmin/<company> works on that
company only: Companies shows just it, global pages are hidden and refused by
URL. A new install-wide screen gets `systemOnly: true`.
**A company's menu is its own (user's rule, 2026-10-25):** what admin/<company>
or superadmin/<company> saves in Manage Menu stays in that company and is
changed only from there. Only superadmin/AdminSystem reaches every company
(Save / Reset / the role editor's "show / hide for everyone"), and only after a
confirmation ("applies to all companies"): the screen sends `apply_to_all=1`,
`MenuPositionController` answers 409 without it.
**Lists = no restriction on AdminSystem:** every list / report endpoint reads
its company with `ScopesToCompany::listCompanyId()` (null = every company for
superadmin/AdminSystem; that company for superadmin/<company>; own company
for everyone else) or `scopeListToCompany()`, adds `company_name` to its rows
(`App\Support\CompanyNames::attach()`), and the page shows a Company column
when `useSystemContext().isSystem` (`lib/systemContext.js`). Reports:
`ScopesReportsToCompany::reportCompanyId()` (999 = all); warehouses:
`ScopesWarehouseAccess::warehouseScopeIds()` (999 = all). Writes (a new
record's company, settings, number generation) keep `resolveActingCompanyId()`;
form pickers stay on the acting company. Numbers unique install-wide
(patients.mrn, students.admission_number) are generated with
`withoutGlobalScope('company')`.

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
  **Business Modules switches never apply to the SuperAdmin (user's rule,
  2026-10-19):** with any company chosen, the SuperAdmin sees and opens every
  module (menu `ALL_MODULES_ON`, `auth.moduleEnabled`, `EnforceModuleFlags`
  and `ModuleFlags::abortUnlessEnabled` skip it); the company's switches apply
  to that company's users. Business logic tied to a switch (ZATCA reporting,
  PayPal) still follows `ModuleFlags::enabled()`. A new company's modules come
  from its business type (`BusinessTypes::modules()`, Companies -> Add ->
  Modules step).
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

Applies every time a module is **created or modified** (Wake-up reloads it at
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
- **Online booking (/book, 2026-10-07):** only the name is required (email and
  phone optional; an email given must be valid). No email to the customer when
  they book -- only the company's admins get "new online booking"; the customer
  gets "your appointment is confirmed" when staff confirm it (if they gave an
  email; never the Walk-in / default customer). No email = a new customer.
  Limits per IP (`booking_public_requests`): 5 bookings / 10 min; from the 4th
  in 24 h the email is required and the booking waits for the "I agree" link we
  email (then that IP is free for 24 h). "Exit" / "Close" go to the code's
  `?return=` website, only if it's in Online Booking -> Websites & codes
  (`settings.booking_websites`), else the first one, else the company website.
- **Public links use `App\Support\PublicUrl`** (2026-10-07), never the request's
  host / `route()`: the company's Public address (Defaults -> General,
  `settings.public_base_url`) else the server address `APP_URL` (System Settings
  -> General -> Server address, SuperAdmin; production = https://murano.kcoltech.com).
  Booking codes, and anything else pasted outside the app, are built with it.
- **Language follows the computer** (user's rule, 2026-10-19): a device with
  no language chosen opens Murano -- login page, customer portal, PayPal
  pay page -- in the browser's language when it's an active one (English /
  Spanish; `SetLocale::browserLocale()`, `i18n browserLocale()`), else the
  company's `default_language`; the login page EN / ES switch (`/set-locale`
  -> cookie `app_locale`) wins over the browser there.
  **Inside the app, after sign-in, the company's language wins** (user's rule,
  2026-10-19, AquaExpress = Spanish showed English): the user's own pick in the
  app (`language_explicit`) first, then the company's `default_language`, then
  the device pick / browser (`stores/auth.js`).
  The standard login page texts have Spanish (migration
  2026_10_19_130000); a company's own texts need their own Spanish in
  Settings -> Login Page.
- **Standard logins (user's rule, 2026-10-25):** every new company and every
  demo gets them (`App\Support\CompanyLogins`): default passwords per type
  employee `Empleado2020`, customer `Cliente2024`, supplier / sales agent /
  store `C0ntr0l123`; and three access logins with their own roles,
  `{slug}-admin` (Admin-ALL), `{slug}-helpdesk` (Helpdesk), `{slug}-info`
  (Info) `@kcoltech.com`, password `C0ntr0l123$` (Companies -> Add: an empty
  password field = these). A demo's people (staff, patients, students...)
  get their type's password (`DemoRunner::standardLogins`). Vitalis has them
  (migration 2026_10_25_190000). **Company logins** (profile menu, the
  company's admin only: admin login + `company_logins_view`): every login, its
  role and accesses, the password when still standard (bcrypt-checked, a
  changed one shows "custom"), a filter by type and by role, and **Enter** =
  work as that login in the same tab (`/login/as/{id}`, `AdminSwitch` cookie,
  header "Back to admin"; a customer opens the portal instead; any other open
  tab reloads as the login in use).
- **Customer portal on by default, every company** (user's rule, 2026-10-25):
  `users.portal_enabled` defaults to 1 and `LinkedUserService` sets it for
  customers; only a deleted customer or one who closed their own portal
  account is off. Admins can still turn one off in People -> Customers.
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
- **Global processes are transactional** (user's rule, 2026-10-07): an action
  that changes several things does all of it in one DB transaction or none.
  **Removing an employee** = `App\Services\EmployeeRemoval` (employee out with
  leaving date, login disabled + signed out everywhere, technician / sales agent
  inactive, open service jobs / upcoming bookings / open tickets unassigned with a
  note, history kept, assets to collect reported). **Disabling a login** =
  `App\Support\UserAccess::disable()` (statut 0 + every token / session revoked);
  `Is_Active` refuses a disabled login before the request runs.
- **Sale numbers are per company** (2026-10-07): `SalesController::getNumberOrder($warehouseId, $companyId)`
  = the company's own `settings.sale_prefix` + that company's highest number + 1,
  keeping its separator (Ruba INV_3604, KcolTech PP-…). Every place that creates a
  sale passes the location / company (sales, POS, bookings, contracts, online store).
- **Header icons** (2026-10-07): System Settings -> Defaults -> Users -> Header
  icons (whole company) and Profile -> Header icons (each user: Company default /
  Show / Hide), `App\Support\HeaderIcons` + `lib/headerIcons.js`, the topbar uses
  `auth.headerIcon(key)`. POS / Settings / Language keep their older switches
  (pos_settings.show_pos_button, customize_button_visible, show_language) as the
  company value. A new header icon gets a key in both lists. Each user can
  also change the icons' left-to-right order in Profile (2026-10-09; stored
  as `_order` in `users.header_icons`, `HeaderIcons::userOrder()`, topbar
  slots use `auth.headerOrder(key)` as the flex order -- a new icon goes in a
  `tb-slot` too).
- **Network security** (user's rule, 2026-10-16): per company (Settings ->
  Network security, permission `network_security`, default off; on for
  KcolTech). Staff (admin / employee logins) can use the **admin panel only**
  from the company's networks (`settings.network_allowed`, IPs or CIDR ranges)
  or from an **approved computer** (key in the HttpOnly cookie
  `murano_device`, hash in `trusted_devices`; admins approve / remove). Checked
  at sign-in (`LoginController`) and on every staff request (`Is_Active`) via
  `App\Support\NetworkAccess`. Never applies to the SuperAdmin, customers,
  suppliers, the store, websites, portfolio, online booking or the portals.
  Users follow the company default unless `users.network_access` says
  `restricted` / `anywhere`. Production needs the server to pass the
  visitor's real IP (Hostinger proxy) before turning it on there.
- **PayPal module** (user's rule, 2026-10-19): customers pay invoices into
  the company's OWN PayPal account. Business Module `paypal`, **off by
  default** (`ModuleFlags::DEFAULT_OFF` / modules.js `defaultOff`), on only
  for KcolTech; Ruba, New Era and Vanclem stay off until asked. Settings ->
  PayPal (`paypal_manage`, `paypal_settings`: Client ID / encrypted Secret,
  sandbox / live, webhook id, deposit account). The "Pay now" page is
  `/pay/{sale}` (relative signed link on `PublicUrl`,
  `PayPalInvoicePayment::payLink`; `{pay_link}` tag in invoice email / SMS /
  WhatsApp, "PayPal pay link" in the Sales row menu); a capture is recorded by
  `PayPalInvoicePayment::record()` (one transaction, once per capture: payment
  method PayPal, account balance, sale paid / status, `paypal_payments`).
  `PayPalService::forCompany($id)` = that company's keys; the online store's
  checkout still uses `store_settings` (not multicompany yet).
- **Hospital = commerce (user's rule, 2026-10-21, replacing 2026-10-20's
  "hospital without Sales / Purchases / Suppliers"):** a patient IS a
  customer (`patients.client_id`, `App\Services\PatientClient` from the
  `Patient` saved hook: same email + company = same customer, else a new one
  with its login); a hospital bill IS a sale (`hospital_invoices.sale_id`,
  `hospital_payments.payment_sale_id`, `App\Services\HospitalSale`:
  medicines = their products with stock, other lines = the company's
  HMS-* service products; accounting comes from the sale, the hospital no
  longer posts its own entries); medicines are bought from suppliers. The
  hospital type has Sales, Purchases and Suppliers on (`BusinessTypes::EXCLUDE`
  = POS only); its demo has pharma suppliers, purchases and bills as sales.
- **Hospital phase 1 (user's analysis, 2026-10-25):** Hospital ->
  **Configuration** (`hms_setup`, `HospitalSetupController`): the company's
  shifts with their hours (`hms_shift_types`, `HospitalShifts::types()` --
  never the fixed constants), floors (`hms_floors`, map grid), rooms / offices /
  ER / nursing... (`hms_rooms`, position on the map) and beds in rooms; floor
  map editor (`FloorMap.vue`) + "Build from the wards" (`HospitalLayout`).
  A bed freed by discharge / transfer / ER close goes to **cleaning**
  (`HospitalBed::release()`), never straight to available; one click marks it
  clean. The admission desk is compact (patient / triage / destination on one
  line, windows for a new patient, the consultation data and a visual bed
  picker on the floor maps). In a hospital company the side menu starts hidden
  and every hospital screen shows the role's forms as buttons
  (`hospitalNav.js`, `HospitalQuickNav`, `/hospital/home`). Printouts: visitor
  pass and patient wristband with the record barcode (`hospitalPrint.js`).
- **Hospital phases 2-4 (2026-10-25):** the stay's administrative file and
  account (`HospitalAccount`, Admissions -> $: origin, coverage, consents,
  deposit, close -> one invoice with insurer / patient split, DRG rates); the
  clinical chart (`ClinicalDrawer.vue`, `HospitalClinicalController`: Kardex
  with wristband scan, SOAP notes, vitals, care / diet orders, consults,
  discharge plan, epicrisis; discharge keeps the bed until "Empty bed") and
  the **Control tower** (`/hospital/control-tower`). Lab: an inpatient's
  order is at least urgent, critical values alert until someone presses
  "Seen" (`critical_seen_at`), images / reports are private files
  (`files/company_{id}/lab/`). Shifts: Plan month / semester repeats a model
  week with rotation (`hms_shift_plans`, undo from today on), coverage
  calendar and the bell alert. **Patient portal** = the customer portal's
  "My hospital" (`PortalHospitalController`, only when the customer is a
  patient): the day's status the nurses publish (never diagnoses, results or
  medication), diet, visit hours, discharge prescriptions / home care /
  videos (Configuration -> Patient portal), appointments;
  `hospital:appointment-reminders` emails the patient 24 h before.
  **Doses: only today's can be recorded** (`HmsMedicationDose::recordableToday`);
  past days' unrecorded ones become `missed` ("Not given (not recorded)",
  `markMissed()` on opening the sheets + nightly command). **My round**
  (`/hospital/rounds`, `HospitalRoundsController`): the doctor's / nurse's
  patients of the shift on the floor plan with the numbered route, a card per
  patient (suffers from, allergies, today's doses, care due, vitals, recent
  labs) and the visit modal (`RoundVisit.vue`: Today / Nursing -- doses, care,
  vitals, "report a change" = nursing note (`hms_progress_notes.kind`) /
  Doctor -- SOAP and prescription on the active treatment).
  **Admission window + triage (user's rules, 2026-10-25):** the window
  (Admission) registers the patient, the money (private / public insurance, or
  a credit card = type + last 4 digits only) and why they come; arrival type
  (`er_cases.pathway`): **emergency** -> waiting for triage (the desk can't put
  them in a bed), **critical** -> an ER bed now, registration and triage
  completed after the patient is seen ("Registration pending" / "Complete
  registration"), **scheduled** (referred, e.g. surgery) -> must come with the
  money, waiting for a bed, no triage. **Triage** (role Triaje, `hms_triage`,
  `/hospital/triage`, `HospitalTriageController`): call the patient, vital
  signs, weight, height (BMI), reason, allergies / chronic / usual medication,
  level 1-5, send to a free ER bed. **Without financial data an emergency
  patient is never admitted to a ward** (`ErCase::hasFinancialData()`: desk
  call-in to a ward bed and ER close as "admitted" refuse it): waiting room or
  ER bed until sent home.
- **Employee details: View / Edit per tab** (`employee_tab_{tab}_{view|edit}`,
  Roles -> HRM -> Employees; edit needs view), enforced server-side by
  `App\Support\EmployeeTabs` (also the company + own-record boundary for the
  endpoints behind the tabs). A new tab on that page gets its own pair of
  permissions.

### User manual (user's rule, 2026-10-07)

- **In-app manual**: the header **book** icon (`manual` header-icon key)
  opens `components/ManualDrawer.vue` -- **This page** (the entry / sections
  for the screen in use) or **Whole manual** (with search), in English or
  Spanish (follows the app language, switchable in the drawer). Content:
  `resources/src/manual/manual.js`, one entry per module with `module`
  (company switch, `config/modules.js`), `perms` (any) and screen `paths`;
  sections can narrow those again. A user only sees what their company has
  turned on and their role allows.
- **The manual is updated ONLY when the user asks for it** (user's rule,
  2026-10-24, replacing the 2026-10-07 "every change updates the manual in
  the same session"): changes to modules, forms, screens, roles or
  permissions do NOT touch `manual.js` nor the overview by themselves, and
  Pa Llevar skips its manual step unless the user asks. When asked, the
  entry is written in English AND Spanish; a new module gets a new entry.
- The overview by company and role (`KT-TOOLS/Manual/murano_user_manual.html`
  + `.pdf`, `node KT-TOOLS/Manual/export_pdf.cjs`) likewise only on request.

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
- **Portfolio tablet catalogs** (user's rule, 2026-10-25): besides the website
  portfolio, a company has as many full-screen dark catalogs for a tablet
  handed to the customer as it needs (`portfolio_catalogs`, Portfolio ->
  Tablet catalogs, header button "Catalog" like POS, page `/c/{key}`): type
  **menu** (restaurant / food: slider on top, group buttons, 2-column grid of
  dishes) and **models** (salon / barber: the catalog's steps, e.g. Woman / Man,
  Adult / Child, filter the services). Dishes / services are Products (picture,
  name, price from there). An order = one transaction (`CatalogOrderService`):
  a POS held order (draft_sales, charged from POS -> Recent drafts) + a Kitchen
  ticket on that draft (`kitchen_orders.draft_sale_id`, source `tablet`) moved to
  the sale when the cashier charges it. Demos: `php artisan portfolio:catalog-demo`.
  A catalog speaks its company's language (`settings.default_language`; New
  Era = English: Men / Women, Adult / Kids). An item can have **model photos**
  (`portfolio_catalog_items.photos`, editor -> "Models": upload, take a photo
  with the device camera, or paste a web link -- an image or a page's og:image,
  public addresses only; saved as JPEG max 1600 px, EXIF-rotated): tapping the service
  picture on the tablet shows them, the customer picks one ("Model N" + photo on
  the order line). New Era's barber demo photos are CC0 (Wikimedia Commons,
  `files/company_3/portfolio/models/barber/CREDITS.txt`).

## Frontend Conventions

(Reloaded by Wake-up, like the Development Standards.)

- **Dropdowns are alphabetically sorted by default.** Any `<a-select>`/combobox
  option list (Vue `computed` building `{value, label}` options) must be
  sorted alphabetically by its displayed label, using the shared
  `sortByLabel()` helper (`resources/src/lib/filterOptions.js`) -- wrap the
  options array in it rather than writing an ad-hoc `.sort()`. Apply this
  when creating a NEW dropdown, not just when fixing an existing one.
  Exceptions need a deliberate reason (e.g. a "Manage Menu" admin-defined
  order, or a list where recency/relevance genuinely matters more than
  alphabetical) -- default to alphabetical unless there's a reason not to.
- **Dates follow the company's country and can be typed (user's rule,
  2026-10-25).** `settings.date_format` comes from `companies.country`
  (`App\Support\DateFormats`: Peru / Spanish-speaking DD-MM-YYYY, USA
  MM-DD-YYYY; set again when the country changes). `<a-date-picker>` and
  `<a-range-picker>` are replaced app-wide by `components/CompanyDatePicker.js`
  (main.js): they show that format with a "dd-mm-aaaa" hint and accept typing
  with or without separators, 2- or 4-digit year (`lib/dateInput.js`); the
  stored `value-format` never changes. Don't hard-code a display `format` on a
  new date field -- the wrapper sets it.
- **Links to another module's page respect the role** (2026-10-06): use
  `<PermLink :to="..." permission="Sales_view">` (global component,
  `components/PermLink.vue`) instead of a bare `<router-link>` -- a user whose
  role can't open the target sees plain text, never a link to "no access".
  Use the target route's `meta.permission`.
- **Lists come back as they were left (user's rule, 2026-10-13).** A list
  with filters registers them with `useListState(key, { filters, datePreset,
  ... }, crud)` (`composables/useListState.js`) during setup, before its first
  fetch: when the user returns with Back / Forward (the page's Back =
  `$goBack`, or the browser's) the filters, dates, page, page size, search
  and sort are restored; opening the list from the menu starts fresh. Forms
  opened from a list return with `goBack(router, '/list')` on **Save** and
  **Cancel** (never a bare `router.push('/list')`, which drops the filters).
  Done for Sales, Purchases, Quotations, Customers, Suppliers -- apply it to
  any list that gets filters.
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
  it's genuinely unclear. **Rows go to production** (2026-10-20):
  `$ktLog = [...]; require 'database/scripts/protocol_kt_log.php'` (local
  only when production is unreachable; `murano:from-prod` sends those up).
- **Wake-up** (`/wake-up`, "Jarvis, wake up" / "protocolo wake-up"; also what
  "preparate" means now) — the start of the day on any PC (user's rules,
  2026-10-19 / 2026-10-20). Steps: git pull (GitHub Desktop's last fetch
  without credentials; composer / npm ci only when needed) ->
  `php artisan murano:from-prod` (this PC's missing `kt_logs` up, production
  dumped on the server + gzip, downloaded, local tables dropped and the dump
  imported, production's new files, local `migrate`, `optimize:clear`; if the
  dump fails dev is untouched; `--backup` = local backup first, only when
  asked) -> `npm run build` -> production code: dry run of
  `protocol_prod_deploy.php`, deploy **only with the user's yes** (git bundle
  -> fast-forward in `public_html`, this PC's `public/js`, composer when
  needed, migrations, production `.env` kept, maintenance page only during the
  change, `$rollback` undoes the code) -> CLAUDE.md + `kt_logs` history
  reloaded (memory digest), Kelsy catch-up -> summary in the chat (no HTML
  report), with the last 5 changes (date + origin).
  **Production owns the business data; every dev database change -- schema and
  the data a feature needs (permissions, settings, translations, menus) -- is a
  migration, safe to run twice, by name not by id.** Production is backed up
  before every write (`KT-TOOLS/database/production/`, git-ignored, compressed,
  phpMyAdmin-ready); if the backup fails nothing is written. `public/` stays out
  of Git LFS (`.gitattributes` = LFS only for KT-TOOLS zips). Production access
  lives ONLY in `.env.production-db` / `.env.production-ssh` (per machine,
  git-ignored) -- never in `.env`, this file, README, kt_logs, reports or
  commits.
- **Pa Llevar** (`/pa-llevar`, "Jarvis, run protocolo pa llevar") — the end of
  the day (A Dormir and Sube Git were merged into it): Wake-up's production
  code step (deploy with the user's yes; no data goes up), the user manual (in-app EN + ES, overview HTML + PDF),
  `npm run build`, Kelsy (no HTML daily report), a local backup tagged
  `pallevar` (+ copies to `KT-TOOLS/SQL/backup db.zip` and `backup db office
  emails 1/2/3.zip` for the external backup program), `README.md` (Latest
  changes, last 5 kept, plus every section the work changed), commit with a
  summary subject and a detailed description, push (never force-push; without
  CLI credentials the user pushes from GitHub Desktop).
- **Copia BD** (`/copia-bd`, "Jarvis, run protocolo copia bd") — a database
  copy on demand, tagged `copiabd`. Same script as Wake-up's / Pa Llevar's
  backups (`database/scripts/protocol_copia_bd.php`, `$backupTag`):
  `KT-TOOLS/database/<PC name>/dbmurano_<date>_<time>_<tag>.zip` (every table
  except the Email Office ones) + `..._<tag>_office_emails_1/_2/_3.zip`
  (`office_emails` in three parts of about the same size; file 1 has the
  tables' structure, file 3 the triggers). Restore = unzip all, import the main
  one, then office_emails 1, 2, 3 in order. Every backup is phpMyAdmin-ready
  (imports as-is into any selected database, e.g. Hostinger): all CREATE TABLE
  first, then data, then triggers; no CREATE DATABASE / USE / LOCK TABLES /
  DEFINER / MariaDB sandbox line; portable utf8mb4_unicode_ci collations
  (`protocol_sql_clean.php`). Git-tracked (LFS), so the copies travel to every
  PC.Billing's originals stay untouched (billing is read-only, per the Hard Rules
below) — these are Murano's own copies, kept in sync manually if the protocol
logic evolves.

## Hard Rules

1. Never modify billing's code or `dbbilling` — read-only source of truth for
   porting logic/data structure only.
2. Murano's own new code/architecture is kept; billing is adapted to fit it,
   not the reverse.
3. Every form/query touching company-scoped data must respect the
   SuperAdmin-vs-Admin multicompany rule above.
