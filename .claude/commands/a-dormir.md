---
description: "Protocol A Dormir -- the end of the day on a developer PC WITHOUT touching production (user's rule, 2026-10-20): a local dev backup (KT-TOOLS/database/<PC>/..._adormir.zip + a copy in the root of KT-TOOLS/database/), a production backup (KT-TOOLS/database/HOSTINGER/hostinger-<date>_<time>-<PC>-a-dormir.zip, git-ignored), Kelsy catch-up, README, commit and push. No deploy, no migrations or data sent to production -- for work not ready / not tested yet. Triggers on \"Jarvis, a dormir\" / \"run protocol a dormir\" / \"protocolo a dormir\"."
---

Run Protocol A Dormir (user's rule, 2026-10-20). In this order. Stop and report if a step fails; never skip ahead silently.

**What it is for:** closing the day on a developer PC and putting the work on GitHub so the other PCs get it, while the changes are NOT ready / tested -- so **production is never written to** (no `protocol_prod_deploy.php`, no migrations there, no data). Production is only READ, for its backup. When the work is ready, **Pa Llevar** (or Wake-up step 4) deploys it.

0. **Which PC** -- run `hostname` (don't ask the user): the PC name for the file names, and the Kelsy location (`KT-THUNDERA` -> Thundera, `KTOPTIMUM_NB` -> Optimum, any other -> Home).

1. **Local dev backup** -- `php artisan tinker --execute="$backupTag = 'adormir'; require '<repo>\database\scripts\protocol_copia_bd.php';"` (absolute path):
   - `KT-TOOLS/database/<PC name>/dbmurano_<date>_<time>_adormir.zip` + `..._adormir_office_emails_1/_2/_3.zip`, compressed and phpMyAdmin-ready;
   - a **copy in the root** of `KT-TOOLS/database/` (`dbmurano_<date>_<PC>_<time>_adormir...zip`) and whatever was in the root before is **moved to its PC's folder** (`protocol_backup_tidy.php`, automatic).
   Note the file names and sizes.

2. **Production backup** -- `php artisan murano:prod-backup` (read-only on production; needs `.env.production-db`, and `.env.production-ssh` for the fast way):
   - `KT-TOOLS/database/HOSTINGER/hostinger-<YYYY-MM-DD>_<hh-mm-ss-AM>-<PC>-a-dormir.zip`, compressed and phpMyAdmin-ready;
   - dumped on the server and downloaded (about a minute); remote MySQL as fallback (~7 min);
   - `HOSTINGER/` is **git-ignored** (real customer data): each PC keeps its own copies, they are never committed.
   Without production access on this PC: skip it, say so in the report.

3. **Kelsy catch-up** -- every request of this conversation not yet in `kt_logs` gets its row (`.claude/commands/kelsy.md`, written to production by `protocol_kt_log.php`).

4. **README** -- `README.md`, from the `kt_logs` rows being committed: the "Latest changes" block `### <YYYY-MM-DD> (<location>)` at the top (extend today's if it exists), one bullet per change the user would notice, the user's language, no file names; `Setup:` lines when needed (`php artisan migrate`, `npm run build`, `composer install`). Mark the block **"(not in production yet)"** -- these changes were not deployed. Keep the last 5 blocks. Never secrets or customer data.

5. **Commit** -- git on PATH or GitHub Desktop's bundled git. `git status`; stage the work (`git add -A`) but never `.env`, `.env.production-db`, `.env.production-ssh`, credentials / keys, `storage/` runtime files, `vendor/`, `node_modules/`, `KT-TOOLS/database/HOSTINGER/` (git-ignored). Warn before staging any file over 50 MB.
   - **Subject** (<= 72 chars): `a dormir <YYYY-MM-DD>: <the 2-4 main changes>`.
   - **Body**: an overview; one bullet per `kt_logs` row since the last commit (`#<id> <category>: <summary>` + lines from its `changes_detail`); "Migrations" (pending on production); "Not deployed -- production unchanged"; `Backups: KT-TOOLS/database/<PC>/<file>.zip (<size>) + office_emails 1/2/3; production: HOSTINGER/<file>.zip (<size>, not committed)`.
   - Write it to a temp file, `git commit -F <file>`. Never `--no-verify`, never amend.

6. **Push** -- `git push` to `origin`. Rejected because the remote is ahead: `git pull --no-rebase`, resolve only trivial conflicts (ask for anything real), push again. **Never force-push.** No GitHub credentials ("could not read Username"): never ask for a password -- tell the user to press **Push origin** in GitHub Desktop.

7. **Report -- in the chat**: PC, local backup (files + sizes), production backup (file + size), kt_logs rows added, README block, commit hash + subject, push result, what was left out and why, and a reminder that **production was not updated** (Pa Llevar does that when the work is ready).

Why this exists: Pa Llevar ends the day by updating production; A Dormir ends the day on any developer PC without spending time or risk on production for changes that are not ready, while the code still travels to the other PCs through GitHub and both databases are backed up.
