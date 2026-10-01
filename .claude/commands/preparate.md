---
description: "Protocol Preparate -- sync this PC to the latest version (git pull, install/migrate only if needed, npm run build), reload the standing project rules (roles/multicompany, billing vs murano, migration scope) from CLAUDE.md, read the kt_logs history of every change request made to this project, then close out with an HTML status card for the last 2 requests, saved into the repo. Also triggers on \"Claude/Jarvis, run protocolo preparate\" / \"protocolo preparate\"."
---

Run Protocol Preparate:

0. **Sync this PC to the latest version** -- first, so every later step (including re-reading `CLAUDE.md`) works on the newest code. Stop and report if any sub-step fails; never skip ahead silently.
   - **Find git:** `git` on PATH, else GitHub Desktop's bundled one -- the newest `%LOCALAPPDATA%\GitHubDesktop\app-*\resources\app\git\cmd\git.exe`.
   - **Check the working tree first:** `git status --short`. If there are uncommitted changes (other than generated/ignored output), list them and ask before pulling -- they may be unpushed work from this machine. Never stash, reset or discard anything on your own.
   - **Pull:** note the current commit (`git rev-parse HEAD`), then `git pull --no-rebase origin <current branch>`. If it fails only because this session has no GitHub credentials ("could not read Username"), fall back to `git merge --ff-only origin/<branch>` -- that uses whatever GitHub Desktop last fetched -- and tell the user when that fetch happened (`.git/FETCH_HEAD` time) and to click **Fetch origin** in GitHub Desktop if it looks stale. Real merge conflicts: stop and ask. Never force anything.
   - **Report what came in:** `git log --oneline <old>..HEAD` and `git diff --stat <old> HEAD` (or "already up to date").
   - **Only what the incoming changes need:**
     - `composer.lock` changed -> `composer install`.
     - `package-lock.json` changed, or `node_modules` is missing/incomplete (e.g. no `node_modules/vite/bin/vite.js`) -> `npm ci`. If it fails with EPERM on a `.node` file, a stale `vite build --watch` / `npm run dev` process is holding it: find it (`Get-CimInstance Win32_Process -Filter "name='node.exe'"`), say which one you're stopping, stop it, retry.
     - New files under `database/migrations/` -> `php artisan migrate --force` (local `dbmurano`; list the migrations that ran).
   - **Clear caches:** `php artisan optimize:clear` (drops any stale cached config/routes/views -- e.g. a `bootstrap/cache/config.php` that came from another machine).
   - **Build the frontend -- always, even when nothing new was pulled** (`public/js` is gitignored, so each PC must build its own): `npm run build` (cleans `public/js`, then builds admin + customer display + portal + storefront; takes a few minutes -- use a long timeout). Report success or the error.
   - Confirm out loud, briefly: commits pulled (or up to date), what was installed/migrated, build result.

1. **Reload the standing context.** Re-read `CLAUDE.md` in the repo root (sections: Protocol "preparate", Roles & Multicompany Model, Systems, Migration Approach, Session Protocols, Hard Rules). This file is git-tracked, so it's identical on every machine this checkout runs on -- that's what makes this protocol portable across computers. Confirm out loud, briefly, that these rules are active for the rest of the session:
   - SuperAdmin = unrestricted, sees a company dropdown on scoped forms. AdminKT/AdminRG (Admin type) = locked to their own `company_id`, no selector.
   - Billing (`KT-BILLING.LOCAL`, `dbbilling`) is production and **read-only** -- never modify it.
   - Murano (`KT-MURANO.LOCAL`, `dbmurano`, this repo) is the target -- new code stays the base, billing's tables/code get adapted into it.
   - Migration scope: sales, company, menu (make it dynamic like billing), employees, products, translations, kt_log -- nothing outside that list.

2. **Read the `kt_logs` history.** `kt_logs` (in `dbmurano`) holds every request/order ever made to modify this project -- one row per request: what was asked (`request_user`), how it was understood (`claude_understand`), what changed (`files_modified`, `actions`, `changes_detail`), plus `summary`, `category`, `company_context`, `location`, `log_date`. Read it so the session knows what's already been built, changed, or decided before touching anything -- don't redo finished work, contradict an earlier decision, or re-break something already fixed.
   - Start with the compact one-line-per-row view of **all** rows, oldest to newest (the long-text columns are too big to pull wholesale) -- run `database/scripts/protocol_preparate_history.php` the same way as step 4's report (absolute path):
     `php artisan tinker --execute="require '<absolute-path-to-repo>\database\scripts\protocol_preparate_history.php';"`
     Each line is `#id date [location] [status] [category] [company] summary`, with a row count and date range at the end.
   - Then pull `request_user` / `changes_detail` / `files_modified` for any row relevant to the current work (same module, same files, same company) before changing it.
   - Confirm out loud, briefly: how many rows were read, the date range, and the main areas of work so far.

3. **Catch up the log.** If this conversation has requests not yet appended to `kt_logs`, run Protocol Kelsy's full logic first (see `.claude/commands/kelsy.md`) -- the closing report in step 4 is only as good as the log being current. Only ask about `location` if it's genuinely ambiguous and hasn't already been established this conversation.

4. **Close out with the HTML request report (scoped to the last 2 requests).** Run `database/scripts/protocol_preparate_report.php` via tinker, using the file's **absolute path** (a bare relative `require` inside `php artisan tinker --execute="..."` fails with "Failed opening required" -- psysh's RequirePass doesn't resolve it against the project root). Example (adjust the drive/path to wherever this checkout actually lives on this machine):
   `php artisan tinker --execute="require '<absolute-path-to-repo>\database\scripts\protocol_preparate_report.php';"`
   It reads the 2 most recent `kt_logs` rows and writes **`KT-TOOLS/Report/kt_murano_preparate_<YYYY-MM-DD>.html`** (the directory is created if missing). Every report mode (preparate / last N / daily) uses the same, consistent layout (user's request, 2026-10-01: "more details, consistent, date requested, request, solution, grouped by module or file affected"):
   - **Header:** scope, generated date/time + machine, totals per status (color-coded Completed / In Progress / Blocked), and one chip per module with its count (links to the section).
   - **Index table:** `#` · **Date requested** (`log_date` + time logged) · **Module** · request (`summary`) · **Status**.
   - **Sections grouped by module** (POS, Bookings, Sales, Purchases, Products & services, Roles & permissions, Menu, Settings, Invoice PDF, Users & customers, Online store, Reports, Locations, Translations & wording, Protocols & tooling, ...). A request's module comes from the files it touched (`files_modified`, the module with the most files; generic buckets like migrations/settings only when nothing more specific was touched), overridden by its summary for renames/wording and protocol work, and from its wording when it touched no files.
   - **One card per request:** `#id`, status, summary; **Requested** date/time · location · category · company · "Also touches" other modules; **Request** (verbatim `request_user`); **Understood as** (`claude_understand`); **Solution** (the verbatim closing message `done`, rendered: bold, lists, tables); **What changed** (`changes_detail`); **Files affected** listed under their module.
   The module rules live at the top of `database/scripts/protocol_preparate_report.php` (`$MODULES`, `$SUMMARY_MODULE`) -- extend them there when a new area appears, don't special-case in the HTML.
   Open it: in Windows PowerShell run `Start-Process "<path>"` (**not** `start "" "<path>"` -- that's cmd.exe syntax and fails under PowerShell's `start` alias for `Start-Process`, which parses `""` as an empty `-FilePath`) so it opens in the default browser. Report the path either way in case auto-open doesn't work in this environment.
   **Reports are kept and shared (user's rule, 2026-09-30):** every protocol report goes to `KT-TOOLS/Report/`, which is git-tracked -- A Dormir commits it, so every programmer/machine gets them. One file per day per report: re-running the same report the same day overwrites that day's file; older days stay. Ad-hoc "report for the last N requests" = `$reportLimit = N` -> `kt_murano_last<N>_<date>.html`; a whole day = `$reportDate = 'YYYY-MM-DD'` -> `kt_murano_daily_<date>.html` (see the script header). Never write protocol reports to `.claude/report/` or a temp dir again.

Why this exists: the user (jlock) works across 3 machines and wants a single phrase that first brings the current PC up to the latest pushed version (code, dependencies, database schema, built frontend), then re-asserts the project's standing rules (so behavior stays consistent regardless of which machine/session) and gives a quick visual confirmation of what just happened and whether it's actually done.

See CLAUDE.md protocol "preparate" for the full rule set this reloads, and `.claude/commands/kelsy.md` / `memorex.md` for the logging/reporting mechanics this builds on.
