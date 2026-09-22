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
this file's rules, then closes out by generating and opening a small HTML
status report (`database/scripts/protocol_preparate_report.php`, written to
`.claude/report/kt_murano_preparate_report.html` -- inside the repo, not a
temp dir; gitignored, regenerated every run) showing the **last 2** `kt_logs`
requests as separate cards, what changed, and each one's **status**
(Completed / In Progress / Blocked -- see the `status` column note in
`.claude/commands/kelsy.md`). This is distinct from Memorex, which reports
the *full* `kt_logs` history.

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
