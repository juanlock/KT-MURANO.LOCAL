---
description: "Protocol Pa Llevar -- the end of the day on any PC: offer to deploy this PC's code to production (the user's yes; no data goes up), then the close-out: user manual (EN + ES, overview + PDF), build, Kelsy, local backup KT-TOOLS/database/<PC>/..._pallevar.zip (+ KT-TOOLS/SQL copies), README, commit and push. Replaces Sube Git. Triggers on \"Claude/Jarvis, run protocol pa llevar\" / \"protocolo pa llevar\"."
---

Run Protocol Pa Llevar ("to go") -- the end-of-day counterpart of Wake-up (`.claude/commands/wake-up.md`; user's rule, 2026-10-19). In this order. Stop and report if a step fails; never skip ahead silently.

0. **Which PC** -- run `hostname` (don't ask the user): the PC name for the backup names, and the Kelsy location (`KT-THUNDERA` -> Thundera, `KTOPTIMUM_NB` -> Optimum, any other -> Home). Work not ready for production yet: use **A Dormir** instead (`.claude/commands/a-dormir.md`).

1. **Production code** -- Wake-up's step 4 (after this protocol's commit, step 7, when today's work is not committed yet -- the deploy needs everything committed): dry run of `protocol_prod_deploy.php`, then ask (deploy / not now); never without the user's yes. **No data goes up** (user's rule, 2026-10-20: production is the only real database; dev data is a disposable copy -- what a feature needs travels as a migration). Note deployed / pending for the report and the commit body.

2. **User manual -- ONLY when the user asks for it in this session** (user's rule, 2026-10-24; otherwise skip this step and say "manual: not requested"). When asked: for every `kt_logs` request of the session (and any not yet logged) that created or changed a **module, form, screen, button, role, permission or company module switch**:
   - **In-app manual** -- `resources/src/manual/manual.js`: that module's entry, **English AND Spanish** (`{ en, es }` side by side). A new module = a new entry (`module` switch key from `config/modules.js`, `perms`, screen `paths`); a new form / button / step = a new or changed section. Bump `MANUAL_UPDATED`.
   - **Overview manual** -- `KT-TOOLS/Manual/murano_user_manual.html` when a role, a role's home page / permissions, a company's modules or a guide's steps changed; then `node KT-TOOLS/Manual/export_pdf.cjs`; update its "Updated" date (and the published page, if it has one).
   - Nothing to do when the session only answered questions or changed data.

3. **Build check** -- `npm run build`. A broken build is never committed: stop and report.

4. **Kelsy** -- every request of this conversation not yet in `kt_logs` (`.claude/commands/kelsy.md`: verbatim request, verbatim closing message in `done`). No HTML daily report any more (user's rule, 2026-10-19, to save resources): the day's requests go in the commit body and the chat summary.

5. **Back up the local database** (it is this morning's copy of production + today's migrations; feeds the external backup program): `php artisan tinker --execute="$backupTag = 'pallevar'; require '<repo>\database\scripts\protocol_copia_bd.php';"` -> `KT-TOOLS/database/<PC name>/dbmurano_<date>_<time>_pallevar.zip` + `..._pallevar_office_emails_1/_2/_3.zip` (compressed, phpMyAdmin-ready). Then copy the four zips over the fixed-name files the external backup program picks up: main -> `KT-TOOLS/SQL/backup db.zip`, `_office_emails_N` -> `KT-TOOLS/SQL/backup db office emails N.zip` (N = 1..3).

6. **README** -- `README.md` (the page GitHub shows), from the `kt_logs` rows being committed:
   - **"Latest changes"**: a `### <YYYY-MM-DD> (<location>)` block at the top (extend today's if it exists): one bullet per change the user would notice, with a short line of detail (where it is, what it does, who can use it), the user's language, no file names; then `Setup: php artisan migrate` / `npm run build` / `composer install` when needed. Keep the **last 5 blocks**.
   - Every other section the work changed: Modules, Key rules (mirror CLAUDE.md briefly), Project layout, Session protocols, Getting started.
   - Never secrets, passwords, server addresses with credentials, real people's emails or customer data.

7. **Commit** -- git on PATH or GitHub Desktop's bundled git. `git status`; stage the work (`git add -A`) but never `.env`, `.env.production-db`, `.env.production-ssh`, credentials / keys, `storage/` runtime files, `vendor/`, `node_modules/` (leave out and mention). Leave out stray files this session didn't create (mention them); files the app generated in `public/files/company_*` travel with the commit. Warn before staging any file over 50 MB.
   - **Subject** (<= 72 chars): `pa llevar <YYYY-MM-DD>: <the 2-4 main changes>`.
   - **Body**: an overview paragraph; one bullet per `kt_logs` row since the last commit (`created_at` later than `git log -1 --format=%cI`) -- `#<id> <category>: <summary>` with indented lines from its `changes_detail`; "Migrations"; "Setup on other machines"; the production sync (came down / went up / pending); `Backup: KT-TOOLS/database/<PC>/<file>.zip (<size>) + office_emails 1/2/3 (<sizes>), copied to KT-TOOLS/SQL`; what was left out.
   - Write it to a temp file, `git commit -F <file>`. Never `--no-verify`, never amend.

8. **Push** -- `git push` to `origin`. Rejected because the remote is ahead: `git pull --no-rebase`, resolve only trivial conflicts (ask for anything real), push again. **Never force-push.** No GitHub credentials ("could not read Username"): never ask for a password -- tell the user to press **Push origin** in GitHub Desktop.

9. **Report** to the user: production sync, manual entries changed, build, kt_logs rows added, backup file + size, commit hash + subject, push result, and anything left out and why.

Why this exists: the user works on Murano from several PCs. One phrase at the end of the day leaves production and dev aligned, the work logged and documented, the database backed up and everything on GitHub, so the next PC's Wake-up starts from the same code, history and data. Billing (KT-BILLING.LOCAL) is never touched.
