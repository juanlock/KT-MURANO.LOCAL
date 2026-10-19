---
description: "Protocol Pa Llevar -- the end of the day on any PC: the same production sync as Wake-up (compare, bring production's new files and records down, offer to update production with the user's yes), then the close-out: user manual (EN + ES, overview + PDF), build, Kelsy, daily report, local backup KT-TOOLS/database/<PC>/..._pallevar.zip (+ KT-TOOLS/SQL copies), README, commit and push. Replaces Sube Git. Triggers on \"Claude/Jarvis, run protocol pa llevar\" / \"protocolo pa llevar\"."
---

Run Protocol Pa Llevar ("to go") -- the end-of-day counterpart of Wake-up (`.claude/commands/wake-up.md`; user's rule, 2026-10-19). In this order. Stop and report if a step fails; never skip ahead silently.

1. **Production sync** -- Wake-up's steps 3, 4 and 5, exactly as written there: compare production with this PC (read only), bring production's new files and records down to dev, and offer to update production when this PC's code / data are newer (production backup first, never without the user's yes). Note what came down / went up / is pending for the report and the commit body.

2. **User manual** (user's rule, 2026-10-07) -- for every `kt_logs` request of the session (and any not yet logged) that created or changed a **module, form, screen, button, role, permission or company module switch**:
   - **In-app manual** -- `resources/src/manual/manual.js`: that module's entry, **English AND Spanish** (`{ en, es }` side by side). A new module = a new entry (`module` switch key from `config/modules.js`, `perms`, screen `paths`); a new form / button / step = a new or changed section. Bump `MANUAL_UPDATED`.
   - **Overview manual** -- `KT-TOOLS/Manual/murano_user_manual.html` when a role, a role's home page / permissions, a company's modules or a guide's steps changed; then `node KT-TOOLS/Manual/export_pdf.cjs`; update its "Updated" date (and the published page, if it has one).
   - Nothing to do when the session only answered questions or changed data.

3. **Build check** -- `npm run build`. A broken build is never committed: stop and report.

4. **Kelsy** -- every request of this conversation not yet in `kt_logs` (`.claude/commands/kelsy.md`: verbatim request, verbatim closing message in `done`). Then the **daily report**: `php artisan tinker --execute="$reportDate = '<YYYY-MM-DD>'; require '<repo>\database\scripts\protocol_preparate_report.php';"` -> `KT-TOOLS/Report/kt_murano_daily_<date>.html` (kept for every programmer; a re-run the same day overwrites it).

5. **Back up the local database -- after steps 1 and 4**, so it holds production's latest records and today's log: `php artisan tinker --execute="$backupTag = 'pallevar'; require '<repo>\database\scripts\protocol_copia_bd.php';"` -> `KT-TOOLS/database/<PC name>/dbmurano_<date>_<time>_pallevar.zip` + `..._pallevar_office_emails_1/_2/_3.zip` (compressed, phpMyAdmin-ready). Then copy the four zips over the fixed-name files the external backup program picks up: main -> `KT-TOOLS/SQL/backup db.zip`, `_office_emails_N` -> `KT-TOOLS/SQL/backup db office emails N.zip` (N = 1..3).

6. **README** -- `README.md` (the page GitHub shows), from the `kt_logs` rows being committed:
   - **"Latest changes"**: a `### <YYYY-MM-DD> (<location>)` block at the top (extend today's if it exists): one bullet per change the user would notice, with a short line of detail (where it is, what it does, who can use it), the user's language, no file names; then `Setup: php artisan migrate` / `npm run build` / `composer install` when needed. Keep the **last 5 blocks**.
   - Every other section the work changed: Modules, Key rules (mirror CLAUDE.md briefly), Project layout, Session protocols, Getting started.
   - Never secrets, passwords, server addresses with credentials, real people's emails or customer data.

7. **Commit** -- git on PATH or GitHub Desktop's bundled git. `git status`; stage the work (`git add -A`) but never `.env`, `.env.production-db`, `.env.production-ssh`, credentials / keys, `storage/` runtime files, `vendor/`, `node_modules/` (leave out and mention). Leave out stray files this session didn't create (mention them); files the app generated in `public/files/company_*` travel with the commit. Warn before staging any file over 50 MB.
   - **Subject** (<= 72 chars): `pa llevar <YYYY-MM-DD>: <the 2-4 main changes>`.
   - **Body**: an overview paragraph; one bullet per `kt_logs` row since the last commit (`created_at` later than `git log -1 --format=%cI`) -- `#<id> <category>: <summary>` with indented lines from its `changes_detail`; "Migrations"; "Setup on other machines"; the production sync (came down / went up / pending); `Backup: KT-TOOLS/database/<PC>/<file>.zip (<size>) + office_emails 1/2/3 (<sizes>), copied to KT-TOOLS/SQL`; `Reports: ...`; what was left out.
   - Write it to a temp file, `git commit -F <file>`. Never `--no-verify`, never amend.

8. **Push** -- `git push` to `origin`. Rejected because the remote is ahead: `git pull --no-rebase`, resolve only trivial conflicts (ask for anything real), push again. **Never force-push.** No GitHub credentials ("could not read Username"): never ask for a password -- tell the user to press **Push origin** in GitHub Desktop.

9. **Report** to the user: production sync, manual entries changed, build, kt_logs rows added, backup file + size, commit hash + subject, push result, and anything left out and why.

Why this exists: the user works on Murano from several PCs. One phrase at the end of the day leaves production and dev aligned, the work logged and documented, the database backed up and everything on GitHub, so the next PC's Wake-up starts from the same code, history and data. Billing (KT-BILLING.LOCAL) is never touched.
