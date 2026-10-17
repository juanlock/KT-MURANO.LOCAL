---
description: "Protocol Sincroniza BD -- compare the production database (Hostinger) with dev (dbmurano): schema, migrations and data; show the differences, then ask which ones to apply to production (backup of production first, one confirmation per change). Triggers on \"Jarvis, run protocolo sincroniza bd\" / \"protocolo sincroniza bd\" / \"syncroniza bd\"."
---

Run Protocol Sincroniza BD (user's rule, 2026-10-13). **Production is live data: compare first, change nothing without an explicit yes.** Dev = this machine's `dbmurano` (Laravel `.env`). Production = the Hostinger database in **`.env.production-db`** at the repo root.

0. **Credentials** -- `.env.production-db` (PROD_DB_HOST / PORT / DATABASE / USERNAME / PASSWORD) is per machine and **git-ignored**: never copy it into `.env` (git still tracks `.env` in this repo), never into this file, CLAUDE.md, README, kt_logs, reports or commit messages, and never print the password. If the file is missing on this machine, ask the user for the values and write it there (only there). Database name / user / host may be named in reports; the password never.

1. **Compare (read-only)** -- `php artisan tinker --execute="require '<absolute-path-to-repo>\database\scripts\protocol_sincroniza_bd.php';"`. It reads both databases (nothing is written to either) and writes `KT-TOOLS/Report/kt_murano_sincroniza_bd_<date>.html` (git-tracked like the other protocol reports; no secrets in it):
   - tables only in dev / only in production;
   - per table in both: columns added / removed / changed (type, null, default) and indexes;
   - migrations run in dev but not in production (production is behind) and the reverse;
   - data per table: rows, MAX(id), MAX(updated_at) on both sides, and CHECKSUM TABLE for tables up to 20,000 rows with the same count (larger ones: count / max only).
   If the connection fails: Hostinger -> hPanel -> Databases -> **Remote MySQL** must allow this machine's public IP (say so; don't retry in a loop).

2. **Show the differences** to the user in plain words, grouped: (a) structure (missing tables / columns / indexes, pending migrations), (b) data (which tables differ and which side looks newer -- more rows, higher max id, later last update). Open the HTML report. **Production may hold newer real data than dev** (people work there): call that out per table, never assume dev is right.

3. **Ask what to update** (AskUserQuestion, or the user's own words): structure only, specific tables' data, or nothing. List the exact tables. Nothing happens without this answer.

4. **Before ANY write to production: back it up.** mysqldump of production (same discovery of mysqldump as Copia BD, credentials from `.env.production-db` passed via a temporary `--defaults-extra-file` deleted right after -- never on the command line) into `KT-TOOLS/database/production/u893406529_dbmurano_<date>_<time>.zip` (that folder is git-ignored: production backups stay on this machine). Confirm the zip exists and its size before going on.

5. **Apply only what was approved, one confirmation per item:**
   - **Structure** -- prefer the Laravel migrations: run the pending ones against production with the production DB_* values set as environment variables for that single command (`php artisan config:clear` first; never edit `.env`), `php artisan migrate --force`. Show the list first. A difference that no migration explains: show the exact `ALTER TABLE` and ask before running it. Never drop a table or column in production without its own explicit yes.
   - **Data (dev -> production)** -- per approved table only: dump that table from dev and import it into production (it REPLACES that table's rows in production). Say how many rows production loses / gains first. Never production -> dev in this protocol (that would be a separate request).
   - Everything for one table in one go; if something fails, stop, report, and point to the step-4 backup.

   - **Scripted** -- `database/scripts/protocol_sincroniza_bd_apply.php`: `$mode = 'backup'` (production backup only), `$mode = 'push'; $tables = [...]` (backup, then each table dev -> production in one transaction). Claude Code's permission check may block 'push' (it writes to production): then don't work around it -- offer the manual way below.
   - **Manual (the user imports)** -- `$mode = 'sql'; $tables = [...]` reads dev only and writes `KT-TOOLS/database/production/sync_to_prod_<stamp>_<n>_<part>.zip` (git-ignored): `1_tables` (each table: START TRANSACTION, DELETE, INSERTs, COMMIT) and, for office_emails, `2/3/4_office_emails_1/2/3` (part 1 starts with the DELETE). The user imports them in production's phpMyAdmin in number order; if an office_emails part fails, re-import from part 1. Make the production backup (`$mode = 'backup'`) first.

6. **Re-compare** (step 1 again) and show what is now equal and what still differs.

7. **Log** it in `kt_logs` (Kelsy rules): what was compared, what the user approved, what was applied, backup file name -- never the password.

Why this exists: Murano runs locally (dev, `dbmurano`) and in production on Hostinger. This protocol shows how far production is from dev before anything is published, and applies changes only with the user's yes, backup first. It is not a code deploy (uploading the app's files is separate).
