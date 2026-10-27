---
description: "Protocol Wake-up -- the start of the day on any PC: update it from git (pull, composer / npm only when needed), replace the local dbmurano with a copy of production (`php artisan murano:from-prod`: database + new files + local migrations, ~1 min), build, offer to deploy this PC's newer code to production (the user's yes), reload the rules and the history, and close with a summary in the chat including the last 5 changes (date + origin). Triggers on \"Jarvis, wake up\" / \"run protocol wake-up\" / \"protocolo wake-up\" / \"buenos días\" when asked to start the day."
---

Run Protocol Wake-up (user's rules, 2026-10-19 / 2026-10-20). In this order. Stop and report if a step fails; never skip ahead silently.

**The model (user's rule, 2026-10-20): production is the only real database.** A developer PC's `dbmurano` is a copy of production, replaced every morning. Code goes up (git -> `protocol_prod_deploy.php`; schema and the data a feature needs -- permissions, settings, translations, menus -- as migrations, safe to run twice, by name not by id). Data comes down. Dev data goes up with Pa Llevar (`protocol_prod_push.php`, user's rule 2026-10-26), never for the **companies in production** (`companies.is_live`: Ruba, New Era, Turismo Cautivo...); what was not sent is gone the next morning. `kt_logs` lives in production (Kelsy writes there).

Needs on this PC (per machine, git-ignored -- never ask for passwords, never print them): `.env.production-db` and `.env.production-ssh`. Without them, steps 2 and 4 are skipped with a note.

0. **Which PC** -- run `hostname` (don't ask the user): the PC name for the report / backup names, and the Kelsy location (`KT-THUNDERA` -> Thundera, `KTOPTIMUM_NB` -> Optimum, any other -> Home).

1. **Update this PC from git.**
   - Git: `git` on PATH, else the newest `%LOCALAPPDATA%\GitHubDesktop\app-*\resources\app\git\cmd\git.exe`.
   - `git status --short`: uncommitted changes (other than generated / ignored output) -> list them and ask before pulling. Never stash, reset or discard on your own.
   - Note `git rev-parse HEAD`, then `git pull --no-rebase origin <branch>`. Without GitHub credentials ("could not read Username"): `git merge --ff-only origin/<branch>` (what GitHub Desktop last fetched) and tell the user the `.git/FETCH_HEAD` time -- "Fetch origin" in GitHub Desktop if it looks old. Real conflicts: stop and ask. Never force anything.
   - Report what came in: `git log --oneline <old>..HEAD`, `git diff --stat <old> HEAD` (or "already up to date").
   - `composer.lock` changed -> `composer install`. `package-lock.json` changed, or no `node_modules/vite/bin/vite.js` -> `npm ci` (EPERM on a `.node` file = a stale `vite build --watch` / `npm run dev` holds it: say which node process, stop it, retry).

2. **Copy production to this PC** -- `php artisan murano:from-prod` (`app/Console/Commands/MuranoFromProd.php`, ~1 minute; long timeout):
   - this PC's `kt_logs` rows production lacks go up first (insert-only, matched by location + summary);
   - `mysqldump | gzip` on the server, downloaded over SFTP (fallback: remote MySQL, ~7 min); if the dump fails the local database is untouched;
   - every local table dropped, the dump imported (copy kept: `<archive>/production/from_prod_latest.zip`, git-ignored);
   - production's new files into `public/files` (`protocol_prod_files.php`);
   - `migrate --force` (this PC's migrations production doesn't have yet -- list them), `optimize:clear`.
   `--backup` makes a local backup first (tag `wakeup`) -- only when the user asks, or when this PC holds something not yet in production that must not be lost (ask first).

3. **Build** -- `npm run build` (`public/js` is not in git; long timeout).

4. **Production code** -- `$apply = false; require '<repo>\database\scripts\protocol_prod_deploy.php';` (dry run): production's commit vs this PC's HEAD, pending migrations. If this PC is ahead, show what would go up and ask (AskUserQuestion: deploy / not now). On yes: `$apply = true` (production backup before migrations, production `.env` kept, maintenance page only during the change, this PC's `public/js` swapped in, `/login` checked). Undo: `$rollback = true`. If Claude Code's permission check blocks a production write, don't work around it: tell the user and leave it pending.

5. **Reload the rules and the history.**
   - Re-read `CLAUDE.md` (it came with step 1) -- its rules apply to the rest of the session.
   - History: `require '<repo>\database\scripts\protocol_preparate_history.php';` (one line per `kt_logs` row -- after step 2 that is production's, i.e. every PC's); read the full rows of anything related to today's work before touching it. Refresh the memory digest `reference_project_activity_digest.md` (overwrite, not append) with the history in narrative form plus "Recurring patterns".
   - Kelsy catch-up: requests of this conversation not yet in `kt_logs` get their row (`.claude/commands/kelsy.md`).

6. **Report -- in the chat only** (no HTML file): git, installs, the production copy (time, size, migrations run locally, files), build, production code status (deployed / pending), and the last 5 changes from `kt_logs` (date, PC, one line each).

Why this exists: the user works on Murano from several PCs and production (Hostinger) has customers adding data every day. One phrase each morning leaves the PC current with git and with production's data, and reminded of the rules and of what changed last. The row-by-row scripts (`protocol_prod_pull.php` / `protocol_prod_push.php`) are no longer part of the day -- only for an exceptional case.
