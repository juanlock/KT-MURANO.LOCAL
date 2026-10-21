---
description: "Protocol Copia BD -- a copy of the local dbmurano on demand: KT-TOOLS/database/<PC name>/dbmurano_<date>_<time>_copiabd.zip (every table except the Email Office ones) + ..._copiabd_office_emails_1/_2/_3.zip, compressed and phpMyAdmin-ready. The same script makes Wake-up's (_wakeup) and Pa Llevar's (_pallevar) backups. Triggers on \"Claude/Jarvis, run protocolo copia bd\" / \"protocolo copia bd\"."
---

Run Protocol Copia BD (a database copy on demand; Wake-up, Pa Llevar and A Dormir make theirs with the same script):

0. **Which PC** -- run `hostname` (don't ask the user): the PC name for the folder / file names, and the Kelsy location (`KT-THUNDERA` -> Thundera, `KTOPTIMUM_NB` -> Optimum, any other -> Home).
1. `php artisan tinker --execute="$backupTag = 'copiabd'; require '<absolute-path-to-repo>\database\scripts\protocol_copia_bd.php';"` -- the **absolute path** (a relative `require` inside tinker fails with "Failed opening required").
2. **Where and what** (user's rules, 2026-10-05 / 2026-10-06 / 2026-10-19): one folder per PC, **`KT-TOOLS/database/<PC name>/`** (`gethostname()`, e.g. `KT-THUNDERA`), four zips per run, never overwritten:
   - **`dbmurano_<date>_<time>_<tag>.zip`** -- every table except the Email Office ones, plus routines and triggers (~4 MB zip);
   - **`dbmurano_<date>_<time>_<tag>_office_emails_1/_2/_3.zip`** -- the Email Office tables (`$emailTables` at the top of the script; add new ones there), `office_emails` cut in three id ranges of about the same size: file 1 also has every Email Office table's structure and the small tables' data, file 3 the triggers.
   - `<tag>` says which protocol made it: **`copiabd`** (this one), `wakeup` (start of the day), `pallevar` (end of the day). The first line of each `.sql` repeats it, with the PC, date and time.
   - `<time>` is 12-hour with AM/PM and hyphens (`08-15-02-AM`), from `now()` (APP_TIMEZONE America/New_York).
3. **Compressed and phpMyAdmin-ready -- every database backup** (user's rule, 2026-10-19: dev and production alike; shared cleaner `database/scripts/protocol_sql_clean.php`): each zip holds one `.sql` that imports as-is through phpMyAdmin -> Import into whatever database is selected:
   - order: every `CREATE TABLE` (+ routines), then every `INSERT`, triggers last (so `kt_logs`' trigger doesn't fire on imported rows); `DROP TABLE IF EXISTS` before each table;
   - no `CREATE DATABASE` / `USE`, no `LOCK TABLES`, no `DEFINER`, no MariaDB 11 sandbox line; MariaDB 11 / MySQL 8 collations rewritten to `utf8mb4_unicode_ci` (structure lines only, data never altered).
   **Restore:** unzip all, import the main `.sql`, then `_office_emails_1`, `_2`, `_3` in that order (phpMyAdmin also takes the `.zip` directly when its upload limit allows).
4. `mysqldump` is found automatically (`MYSQLDUMP_PATH` in `.env` -> PATH -> next to the running MySQL / MariaDB service -> common XAMPP paths); if not, the script says what to add to `.env` and stops without leaving a partial file. Another folder under `KT-TOOLS/` can be forced with `$outSubdir = '...'` before the require.
5. **Tidy + latest copy** (user's rule, 2026-10-19; automatic at the end of every run, `database/scripts/protocol_backup_tidy.php`): any backup zip found loose in `KT-TOOLS/database/`, or in another PC's folder, is moved to its PC's folder (the PC comes from the file name; an old root copy whose original is already in place is just removed), and a **copy of the backup just made is left in the root of `KT-TOOLS/database/`**, named with the PC: `dbmurano_<date>_<PC>_<time>_<tag>[_office_emails_N].zip` -- only the latest one stays there.
6. Report the paths and sizes to the user.

`KT-TOOLS/database/` is git-tracked (zips via Git LFS), so the copies travel to every PC with Pa Llevar -- each run adds ~16 MB, mind the repo size. Production backups are separate: `KT-TOOLS/database/production/` (git-ignored, same format), made automatically before anything is written to production.
