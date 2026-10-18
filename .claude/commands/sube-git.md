---
description: "Protocol Sube Git -- quick push without the full Pa Llevar: catch up kt_logs, check the build, update README.md in detail with the work since the last commit, then commit with a summary + detailed description and push to GitHub. No database backup, no daily report. Triggers on \"Claude/Jarvis, run protocol sube git\" / \"protocolo sube git\"."
---

Run Protocol Sube Git ("push it up") -- a quick way to put the current work on GitHub with an up-to-date README, without Pa Llevar's backup and reports. In this order. Stop and report if a step fails; never skip ahead silently.

1. **What is being pushed** -- `git status` and `git log -1 --format=%cI` (git on PATH, or GitHub Desktop's bundled git, e.g. `C:\Users\<user>\AppData\Local\GitHubDesktop\app-<version>\resources\app\git\cmd\git.exe`). Nothing changed and nothing to push -> say so and stop.

2. **Kelsy catch-up** -- every request of this conversation not yet in `kt_logs` gets its row now (rules of `.claude/commands/kelsy.md`: verbatim request, verbatim closing message in `done`). Then read the `kt_logs` rows since the last commit (`created_at` later than the commit date from step 1): they are the source for the README and the commit message.

3. **Build check** -- `npm run build`. It must finish without errors: a broken build is never pushed. If it fails, stop and report the error.

4. **README in detail** -- bring `README.md` (the page GitHub shows) up to date with that work:
   - **"Latest changes"**: a `### <YYYY-MM-DD> (<location>)` block at the top (if today's block already exists, extend it instead of adding a second one). One bullet per change the user would notice -- new modules / pages / buttons / filters, roles and permissions, rules, important fixes -- each with a short line of detail (where it is, what it does, who can use it). The user's language, no file names. Then `Setup: php artisan migrate` / `npm run build` / `composer install` when the work needs them. Keep the **last 5 blocks**; older ones are dropped (the full history is `kt_logs`).
   - **Every other section that the work changed**: Modules (a new module or page), Key rules (a new rule or convention -- mirror CLAUDE.md, briefly), Project layout (a new helper or folder), Session protocols (a protocol added / changed / removed), Getting started (new setup steps).
   - Never put secrets, passwords, server addresses with credentials, emails of real people or customer data in it -- it is shown on GitHub.

5. **Commit** -- stage the work (`git add -A`), but never stage secrets or machine-local files: `.env`, `.env.production-db`, credentials/keys, `storage/` runtime files, `vendor/`, `node_modules/`, production logs (if one shows up, leave it out and mention it). Leave out stray files this session didn't create (mention them). Warn before staging any single file over 50 MB (GitHub rejects files over 100 MB).
   - **Subject** (one line, <= 72 chars): `sube git <YYYY-MM-DD>: <the 2-4 main changes>`.
   - **Body**: a short overview paragraph, then one bullet per `kt_logs` row -- `#<id> <category>: <summary>` with indented detail lines from its `changes_detail` -- then "Migrations" (new files under `database/migrations/`), "Setup on other machines" (`php artisan migrate`, `composer install`, `npm run build` when they apply), and what was left out.
   - Write it to a temp file and `git commit -F <file>`. Never `--no-verify`, never amend.

6. **Push** -- `git push` to `origin` on the current branch. If rejected because the remote is ahead: `git pull --no-rebase`, resolve only trivial conflicts (stop and ask for anything real), push again. **Never force-push.** Without GitHub credentials in this session ("could not read Username"), don't ask for or accept a password -- tell the user to press "Push origin" in GitHub Desktop.

7. **Report** to the user: build result, kt_logs rows added, the README sections updated, the commit hash + subject, the push result (or "push from GitHub Desktop"), and anything left out and why.

**Sube Git vs Pa Llevar:** Sube Git is the quick push (logs, build, README, commit, push). Pa Llevar is the full end-of-session close-out -- it also updates the user manual, writes the daily report and backs up the database (Copia BD). Use Pa Llevar at the end of the day / before switching machines when the data must travel too.

**Not a production deploy.** It only pushes to GitHub (the private repo shared by the user's machines); nothing is uploaded to a production server.
