---
description: "Protocol Preparate -- reload the standing project rules (roles/multicompany, billing vs murano, migration scope) from CLAUDE.md, then close out with an HTML status card for the last 2 requests, saved into the repo. Also triggers on \"Claude/Jarvis, run protocolo preparate\" / \"protocolo preparate\"."
---

Run Protocol Preparate:

1. **Reload the standing context.** Re-read `CLAUDE.md` in the repo root (sections: Protocol "preparate", Roles & Multicompany Model, Systems, Migration Approach, Session Protocols, Hard Rules). This file is git-tracked, so it's identical on every machine this checkout runs on -- that's what makes this protocol portable across computers. Confirm out loud, briefly, that these rules are active for the rest of the session:
   - SuperAdmin = unrestricted, sees a company dropdown on scoped forms. AdminKT/AdminRG (Admin type) = locked to their own `company_id`, no selector.
   - Billing (`KT-BILLING.LOCAL`, `dbbilling`) is production and **read-only** -- never modify it.
   - Murano (`KT-MURANO.LOCAL`, `dbmurano`, this repo) is the target -- new code stays the base, billing's tables/code get adapted into it.
   - Migration scope: sales, company, menu (make it dynamic like billing), employees, products, translations, kt_log -- nothing outside that list.

2. **Catch up the log.** If this conversation has requests not yet appended to `kt_logs`, run Protocol Kelsy's full logic first (see `.claude/commands/kelsy.md`) -- the closing report in step 3 is only as good as the log being current. Only ask about `location` if it's genuinely ambiguous and hasn't already been established this conversation.

3. **Close out with an HTML status card (like Memorex, but scoped to just the last 2 requests).** Run `database/scripts/protocol_preparate_report.php` via tinker, using the file's **absolute path** (a bare relative `require` inside `php artisan tinker --execute="..."` fails with "Failed opening required" -- psysh's RequirePass doesn't resolve it against the project root). Example (adjust the drive/path to wherever this checkout actually lives on this machine):
   `php artisan tinker --execute="require '<absolute-path-to-repo>\database\scripts\protocol_preparate_report.php';"`
   It reads the 2 most recent `kt_logs` rows and writes a compact HTML page -- one status card per request -- to **`.claude/report/kt_murano_preparate_report.html`** (inside the repo, not a temp dir; the directory is created if missing). Each card shows: **Status** (color-coded badge -- Completed/In Progress/Blocked), **Request** (the `summary`), **Source** (user, location, date/time), **Category**, **Company**, **Changes Made**, and **Actions**.
   Open it: in Windows PowerShell run `Start-Process "<path>"` (**not** `start "" "<path>"` -- that's cmd.exe syntax and fails under PowerShell's `start` alias for `Start-Process`, which parses `""` as an empty `-FilePath`) so it opens in the default browser. Report the path either way in case auto-open doesn't work in this environment.
   `.claude/report/` is gitignored (generated output, regenerated every run) -- don't commit it.

Why this exists: the user (jlock) works across 3 machines and wants a single phrase that both re-asserts the project's standing rules (so behavior stays consistent regardless of which machine/session) and gives a quick visual confirmation of what just happened and whether it's actually done.

See CLAUDE.md protocol "preparate" for the full rule set this reloads, and `.claude/commands/kelsy.md` / `memorex.md` for the logging/reporting mechanics this builds on.
