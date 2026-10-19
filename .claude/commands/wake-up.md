---
description: "Protocol Wake-up -- the start of the day on any PC: update it from git (pull, composer / npm / migrate only when needed, build), back the local dbmurano up (KT-TOOLS/database/<PC>/..._wakeup.zip), compare production with this PC (code, migrations, files, records) and report it, bring production's new files and records down to dev, offer to update production when this PC's code / data are newer (production backup + the user's yes), reload the rules and the history, and close with a report of the current conventions and the last 5 changes (date + origin). Replaces Preparate, Baja Prod and Sube Prod. Triggers on \"Jarvis, wake up\" / \"run protocol wake-up\" / \"protocolo wake-up\" / \"buenos días\" when asked to start the day."
---

Run Protocol Wake-up (user's rule, 2026-10-19: one protocol for every PC just turned on). In this order. Stop and report if a step fails; never skip ahead silently. Production is only READ until step 5, and step 5 never runs without the user's explicit yes.

Needs on this PC (per machine, git-ignored -- never ask for passwords, never print them): `.env.production-db` (production database; this PC's public IP allowed in hPanel -> Databases -> Remote MySQL) and `.env.production-ssh` (SSH / SFTP). Without them, steps 3-5 are skipped with a note in the report.

1. **Update this PC from git.**
   - Git: `git` on PATH, else the newest `%LOCALAPPDATA%\GitHubDesktop\app-*\resources\app\git\cmd\git.exe`.
   - `git status --short`: uncommitted changes (other than generated / ignored output) -> list them and ask before pulling. Never stash, reset or discard on your own.
   - Note `git rev-parse HEAD`, then `git pull --no-rebase origin <branch>`. Without GitHub credentials ("could not read Username"): `git merge --ff-only origin/<branch>` (what GitHub Desktop last fetched) and tell the user the `.git/FETCH_HEAD` time -- "Fetch origin" in GitHub Desktop if it looks old. Real conflicts: stop and ask. Never force anything.
   - Report what came in: `git log --oneline <old>..HEAD`, `git diff --stat <old> HEAD` (or "already up to date").

2. **Back up the local database -- before anything changes it.** `php artisan tinker --execute="$backupTag = 'wakeup'; require '<repo>\database\scripts\protocol_copia_bd.php';"` -> `KT-TOOLS/database/<PC name>/dbmurano_<date>_<time>_wakeup.zip` + `..._wakeup_office_emails_1/_2/_3.zip` (compressed, phpMyAdmin-ready). Note the file names and sizes.
   Then what the incoming changes need, only that:
   - `composer.lock` changed -> `composer install`.
   - `package-lock.json` changed, or `node_modules` incomplete (no `node_modules/vite/bin/vite.js`) -> `npm ci` (EPERM on a `.node` file = a stale `vite build --watch` / `npm run dev` holds it: say which node process, stop it, retry).
   - New files in `database/migrations/` -> `php artisan migrate --force` (list them).
   - Always: `php artisan optimize:clear` and `npm run build` (`public/js` is not in git; long timeout).

3. **Compare production with this PC -- read only, dry runs.**
   - **Code:** `$apply = false; require '<repo>\database\scripts\protocol_prod_deploy.php';` -> production's commit vs this PC's HEAD (commits and files production lacks, composer yes / no, pending migrations, production files that would be overwritten). Production behind = this PC's code is newer.
   - **Production -> dev:** `protocol_prod_files.php` (`$apply = false`: files new / changed in production's `public/files`) and `protocol_prod_pull.php` (`$apply = false`: per table, rows new or changed in production, rows where dev is newer, rows only in dev).
   - **Dev -> production data:** `protocol_prod_push.php` (`$apply = false`: rows / files created or changed in dev since the last sync; conflicts listed, never sent).
   Summarize it for the user in plain words: production code up to date or N commits behind (main changes), migrations pending, files and records waiting to come down, dev data waiting to go up, conflicts.

4. **Bring production down to dev** (writes only to dev; step 2's backup covers it): `protocol_prod_files.php` with `$apply = true` (downloads into `public/files`; a dev copy it replaces is kept in `KT-TOOLS/database/production/files_replaced_<stamp>/`; nothing deleted on either side; Git LFS pointers in production are reported, never downloaded), then `protocol_prod_pull.php` with `$apply = true` (one transaction; the dev rows it replaces saved to `KT-TOOLS/database/production/dev_before_prod_pull_<stamp>.json`; never `migrations`, `kt_logs`, queue / cache / session tables). Point out rows "only in dev" in business tables (sales, payments, products, companies): they would clash with production's next ids.

5. **Update production -- only when this PC is newer, only with the user's yes.** If step 3 found production's code behind and / or dev data to send, show what would go up and ask (AskUserQuestion: code + data / code only / data only / not now). On yes:
   - **Code** -- `protocol_prod_deploy.php` with `$apply = true` (needs everything committed and step 2's build): production `.env` kept, maintenance page only during the change, git bundle -> fast-forward in `public_html`, this PC's `public/js` swapped in (previous kept), composer when needed, pending migrations with a production backup first, workers restarted, `/login` checked (200). Undo: `$rollback = true` (code only; the database backup path is in `~/murano_deploy/last_deploy.json`).
   - **Data** -- `protocol_prod_push.php` with `$apply = true`: production backup first (if it fails nothing is sent), one transaction; conflicts never sent -- ask about each.
   Every production backup is compressed and phpMyAdmin-ready (`KT-TOOLS/database/production/<db>_<date>_<time>_<label>.zip`, git-ignored). If Claude Code's permission check blocks a production write, don't work around it: tell the user and leave it pending in the report.
   Rules: production owns the business data; every dev database change a feature needs (tables, permissions, settings, translations, menus) travels as a migration, safe to run twice, by name not by id.

6. **Reload the rules and the history.**
   - Re-read `CLAUDE.md` (it came with step 1) -- its rules apply to the rest of the session.
   - History: `require '<repo>\database\scripts\protocol_preparate_history.php';` (one line per `kt_logs` row); read the full rows of anything related to today's work before touching it. Refresh the memory digest `reference_project_activity_digest.md` (overwrite, not append) with the history in narrative form plus "Recurring patterns" (most frequent categories / files / themes).
   - Kelsy catch-up: requests of this conversation not yet in `kt_logs` get their row (`.claude/commands/kelsy.md`).

7. **Report.** `php artisan tinker --execute="$wakeSummary = [ ...one line per fact... ]; require '<repo>\database\scripts\protocol_wakeup_report.php';"` -> `KT-TOOLS/Report/kt_murano_wakeup_<date>.html` (git-tracked; a re-run the same day overwrites it): this morning's summary (git, backup file + size, installs / migrations / build, production code / data status, what came down, what went up or is pending), the **current conventions** (read live from CLAUDE.md: Development Standards, Frontend Conventions, Hard Rules) and the **last 5 changes** with date, time and origin (PC + user). Open it (`Start-Process "<path>"`) and give the user the same summary in chat.

Why this exists: the user works on Murano from several PCs and production (Hostinger) has customers adding data every day. One phrase each morning leaves the PC current, backed up, aligned with production in both directions (with a yes for anything that touches production), and reminded of the rules and of what changed last.
