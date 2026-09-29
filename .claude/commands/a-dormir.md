---
description: "Protocol A Dormir -- end-of-session close-out: run Kelsy (log the session to kt_logs), back up dbmurano into KT-TOOLS/database (Copia BD), then commit everything with a summary + detailed description of the changes and push to GitHub. Also triggers on \"Claude/Jarvis, run protocol a dormir\" / \"protocolo a dormir\"."
---

Run Protocol A Dormir ("off to bed") -- the end-of-session close-out, in this order. Stop and report if a step fails; never skip ahead silently.

1. **Kelsy** -- run Protocol Kelsy exactly as defined in `.claude/commands/kelsy.md`: append every request of this conversation not yet in `kt_logs` (one row per request, verbatim request and verbatim closing message in `done`). The only question allowed is the location (Thundera / Home / new) -- and only if it hasn't been answered earlier in this session.

2. **Backup (Copia BD)** -- run Protocol Copia BD exactly as defined in `.claude/commands/copia-bd.md` (`database/scripts/protocol_copia_bd.php` via tinker with its absolute path). It writes `KT-TOOLS/database/dbmurano_<Y-m-d_His>.sql`. Note the file name and size for the commit message and the report. Run it AFTER Kelsy so the dump already contains this session's log rows.

3. **Commit** -- with git (on PATH, or GitHub Desktop's bundled git, e.g. `C:\Users\juanl\AppData\Local\GitHubDesktop\app-<version>\resources\app\git\cmd\git.exe`):
   - `git status` and review what changed. Stage the work (`git add -A`), but never stage secrets or machine-local files: `.env`, credentials/keys, `storage/` runtime files, `vendor/`, `node_modules/` (those are gitignored -- if something like that shows up as untracked, leave it out and mention it). Warn before staging any single file over 50 MB (GitHub rejects files over 100 MB).
   - Build the message from the `kt_logs` rows of the work being committed (the rows added since the previous A Dormir / last commit -- compare `kt_logs.created_at` with `git log -1 --format=%cI`, and include this run's Kelsy rows):
     - **Subject** (one line, <= 72 chars): `a dormir <YYYY-MM-DD>: <the 2-4 main changes>`.
     - **Body**: a short overview paragraph, then one bullet per logged request -- `#<kt_logs id> <category>: <summary>` followed by indented detail lines taken from that row's `changes_detail` (what changed: tables/columns, endpoints, screens, behaviour), then a "Migrations" list (new files under `database/migrations/` in this commit), a "Setup on other machines" note (e.g. `php artisan migrate`, `composer install`, `npm run build:admin` / `build:portal` when those apply) and the backup line `Backup: KT-TOOLS/database/<file> (<size>)`.
     - Pass it with a single-quoted here-string (PowerShell) so `$`/backticks aren't expanded.
   - Commit normally -- never `--no-verify`, never amend an existing commit.

4. **Push** -- `git push` to `origin` on the current branch. If the push is rejected because the remote is ahead, `git pull --no-rebase` (merge), resolve only trivial conflicts (stop and ask for anything real), then push again. **Never force-push.**

5. **Report** to the user: the kt_logs rows added (ids + one line each), the backup file + size, the commit hash + subject, and the push result (branch, remote). If anything was left out of the commit, say what and why.

Why this exists: the user (jlock) works on this project from several machines (Home, Thundera, ...). One phrase at the end of a session logs the work, snapshots the database into the repo and pushes both, so the next machine starts from the same code, history and data. Murano's own protocol (not ported from billing). Billing (KT-BILLING.LOCAL) is never touched by it.
