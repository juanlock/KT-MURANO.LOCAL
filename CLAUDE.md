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
usable the same way on every machine this repo is checked out on: it reloads
this file's rules, reads the `kt_logs` table (the history of every
request/order made to modify this project) so prior work and decisions are
known before anything is changed, then closes out by generating and opening a small HTML
status report (`database/scripts/protocol_preparate_report.php`, written to
`.claude/report/kt_murano_preparate_report.html` -- inside the repo, not a
temp dir; gitignored, regenerated every run) showing the **last 2** `kt_logs`
requests as separate cards, what changed, and each one's **status**
(Completed / In Progress / Blocked -- see the `status` column note in
`.claude/commands/kelsy.md`). This is distinct from Memorex, which reports
the *full* `kt_logs` history.

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

## Frontend Conventions

- **Dropdowns are alphabetically sorted by default.** Any `<a-select>`/combobox
  option list (Vue `computed` building `{value, label}` options) must be
  sorted alphabetically by its displayed label, using the shared
  `sortByLabel()` helper (`resources/src/lib/filterOptions.js`) -- wrap the
  options array in it rather than writing an ad-hoc `.sort()`. Apply this
  when creating a NEW dropdown, not just when fixing an existing one.
  Exceptions need a deliberate reason (e.g. a "Manage Menu" admin-defined
  order, or a list where recency/relevance genuinely matters more than
  alphabetical) -- default to alphabetical unless there's a reason not to.

## Session Protocols (ported from KT-BILLING.LOCAL)

The `kt_log` module migration (see above) included porting these Claude Code
slash-command protocols from billing into this repo, adapted for `dbmurano`
and this repo's own `kt_logs` migration (`database/migrations/2026_09_22_120000_create_kt_logs_table.php`,
model `app/Models/KtLog.php`). Definitions live in `.claude/commands/`:

- **Kelsy** (`/kelsy`, or "Claude/Jarvis, run protocol Kelsy") — append this
  conversation's activity to `kt_logs`.
- **Mañanitas** (`/mananitas`, or "Claude/Jarvis, run protocol Mañanitas") —
  session bootstrap: catch up `kt_logs` via Kelsy, digest project history into
  a memory file, surface recurring change patterns.
- **Memorex** (`/memorex`, or "Claude/Jarvis, run protocol Memorex") —
  generate and open an HTML activity report from `kt_logs`
  (`database/scripts/protocol_memorex_report.php`, writes to
  `%TEMP%/kt_murano_activity_report.html`).
- **Preparate** (`/preparate`, or "Jarvis, run protocolo preparate") — not
  ported from billing, this one is Murano's own: reloads this file's rules,
  then closes out with an HTML status card for the last request (see above).
- **Copia BD** (`/copia-bd`, or "Jarvis, run protocolo copia bd") — not
  ported from billing, this one is Murano's own: dumps `dbmurano` via
  `mysqldump` (`database/scripts/protocol_copia_bd.php`) into
  `KT-TOOLS/database/dbmurano_<timestamp>.sql` — a phpMyAdmin-independent
  backup after a local phpMyAdmin export bug corrupted a manual backup
  attempt. `KT-TOOLS/` is git-tracked, so the snapshot travels across every
  machine this checkout is on, same as the other protocols.
- **A Dormir** (`/a-dormir`, or "Jarvis, run protocolo a dormir") — Murano's
  own end-of-session close-out: runs Kelsy, then Copia BD, then commits
  everything with a summary subject and a detailed description of the
  changes (one bullet per `kt_logs` request, migrations, setup steps for
  other machines, backup file) and pushes to GitHub (never force-push).

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
