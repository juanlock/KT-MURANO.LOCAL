---
description: "Protocol Pa Llevar (formerly A Dormir) -- end-of-session close-out: check the build (npm run build), run Kelsy (log the session to kt_logs), write the day's report, back up dbmurano into KT-TOOLS/database (Copia BD) and refresh KT-TOOLS/SQL/backup db.zip + backup db office emails 1/2/3.zip, then commit everything with a summary + detailed description and push to GitHub. Triggers on \"Claude/Jarvis, run protocol pa llevar\" / \"protocolo pa llevar\"."
---

Run Protocol Pa Llevar ("to go") -- the end-of-session close-out (it absorbed the old A Dormir protocol, which no longer exists). In this order. Stop and report if a step fails; never skip ahead silently.

0. **Build check** -- `npm run build` (all bundles: admin, customer display, portal, storefront). It must finish without errors: a broken build is never committed/pushed. If it fails, stop and report the error. (`public/js` itself is not in git -- this step only proves the code that is about to be pushed builds; every machine still builds after pulling, see Preparate.)

1. **Kelsy** -- run Protocol Kelsy exactly as defined in `.claude/commands/kelsy.md`: append every request of this conversation not yet in `kt_logs` (one row per request, verbatim request and verbatim closing message in `done`). The only question allowed is the location (Thundera / Home / new) -- and only if it hasn't been answered earlier in this session.

1b. **Daily report** -- after Kelsy, write today's report of every request logged today: `php artisan tinker --execute="$reportDate = '<YYYY-MM-DD>'; require '<absolute-path-to-repo>\database\scripts\protocol_preparate_report.php';"` -> `KT-TOOLS/Report/kt_murano_daily_<date>.html` (overwrites today's if it runs twice). It is committed in step 3 together with any other reports generated today under `KT-TOOLS/Report/` -- they are kept for every programmer, never deleted.

2. **Backup (Copia BD)** -- run Protocol Copia BD exactly as defined in `.claude/commands/copia-bd.md` (`database/scripts/protocol_copia_bd.php` via tinker with its absolute path). It writes four zips: `KT-TOOLS/database/dbmurano_<date>_<host>_<time>.zip` (every table except the Email Office ones) and `..._office_emails_1.zip` / `_2.zip` / `_3.zip` (the Email Office tables, `office_emails` in three parts of about the same size) -- see Copia BD step 4b. Note the file names and sizes for the commit message and the report. Run it AFTER Kelsy so the dump already contains this session's log rows.

2b. **Backup DB (external backup file)** -- copy the four new zips over the fixed-name files the user's external backup program picks up (overwritten every run; user's rule, 2026-10-03, zipped and split since 2026-10-05, Email Office in three files since 2026-10-06):
   - main zip -> **`KT-TOOLS/SQL/backup db.zip`**
   - `_office_emails_1` / `_2` / `_3` zips -> **`KT-TOOLS/SQL/backup db office emails 1.zip`** / **`2.zip`** / **`3.zip`**
   Same content as the Copia BD files, so the `.sql` inside each is phpMyAdmin-ready too (restore order: main, then 1, 2, 3). PowerShell: `Copy-Item -LiteralPath 'KT-TOOLS\database\<new dump>.zip' -Destination 'KT-TOOLS\SQL\backup db.zip' -Force; 1..3 | ForEach-Object { Copy-Item -LiteralPath "KT-TOOLS\database\<new dump>_office_emails_$_.zip" -Destination "KT-TOOLS\SQL\backup db office emails $_.zip" -Force }`. The old `backup db office emails.zip` (one file) and unzipped `backup db.sql` are no longer refreshed. Commit the zips in step 3 (git-tracked, via LFS).

3. **Commit** -- with git (on PATH, or GitHub Desktop's bundled git, e.g. `C:\Users\<user>\AppData\Local\GitHubDesktop\app-<version>\resources\app\git\cmd\git.exe`):
   - `git status` and review what changed. Stage the work (`git add -A`), but never stage secrets or machine-local files: `.env`, credentials/keys, `storage/` runtime files, `vendor/`, `node_modules/` (those are gitignored -- if something like that shows up as untracked, leave it out and mention it). Leave out stray files this session didn't create (mention them). Warn before staging any single file over 50 MB (GitHub rejects files over 100 MB).
   - Build the message from the `kt_logs` rows of the work being committed (the rows added since the previous Pa Llevar / last commit -- compare `kt_logs.created_at` with `git log -1 --format=%cI`, and include this run's Kelsy rows):
     - **Subject** (one line, <= 72 chars): `pa llevar <YYYY-MM-DD>: <the 2-4 main changes>`.
     - **Body**: a short overview paragraph, then one bullet per logged request -- `#<kt_logs id> <category>: <summary>` followed by indented detail lines taken from that row's `changes_detail`, then a "Migrations" list (new files under `database/migrations/` in this commit), a "Setup on other machines" note (`php artisan migrate`, `composer install`, `npm run build` when those apply), live-data changes made this session, the backup line `Backup: KT-TOOLS/database/<file>.zip (<size>) + <file>_office_emails_1/_2/_3.zip (<sizes>), copied to KT-TOOLS/SQL/backup db.zip + backup db office emails 1/2/3.zip`, `Reports: KT-TOOLS/Report/<files added/updated>`, and what was left out.
     - Write it to a temp file and commit with `git commit -F <file>` (no shell expansion problems).
   - Commit normally -- never `--no-verify`, never amend an existing commit.

4. **Push** -- `git push` to `origin` on the current branch. If the push is rejected because the remote is ahead, `git pull --no-rebase` (merge), resolve only trivial conflicts (stop and ask for anything real), then push again. **Never force-push.** If this session has no GitHub credentials (push fails with "could not read Username"), don't ask for or accept a password -- tell the user to press "Push origin" in GitHub Desktop.

5. **Report** to the user: build result, the kt_logs rows added (ids + one line each), the backup file + size, the commit hash + subject, and the push result (branch, remote -- or "push from GitHub Desktop"). If anything was left out of the commit, say what and why.

**Not a production deploy.** Pa Llevar syncs code, history and the database snapshot to GitHub (the private repo shared by the user's machines). It does not upload anything to a production server -- that would need its own protocol (server, access method, production DB backup first, migrate there, confirm before deploying).

Why this exists: the user (jlock) works on this project from several machines (Home, Thundera, ...). One phrase at the end of a session checks the build, logs the work, snapshots the database into the repo and pushes, so the next machine (Preparate) starts from the same code, history and data. Murano's own protocol (not ported from billing). Billing (KT-BILLING.LOCAL) is never touched by it.
