---
description: "Protocol Sube Prod (\"actualiza producción\") -- brings production up to date with dev: the data created / changed in dev since the last Baja Prod (users, settings, files... with conflict checks) and this PC's committed code over SSH, in place: git bundle of the new commits -> production's public_html fast-forwards (no GitHub access needed on the server), public/js built here uploaded and swapped, composer install when composer.lock changed, migrations with a production DB backup, production .env always kept, maintenance page during the change. Replaces the Hostinger Git deploy (which kept the project 4-5 times and ran the account out of files). Triggers on \"Jarvis, run protocolo sube prod\" / \"protocolo sube prod\" / \"sube prod\"."
---

Run Protocol Sube Prod ("push it to production") -- user's choice 2026-10-18 instead of the Hostinger Git deploy. Production is changed: always dry run first, show it, and get the user's explicit yes before applying.

Needs on this machine (per machine, git-ignored): `.env.production-ssh` (SSH) and `.env.production-db` (migrations; this PC's IP allowed in hPanel -> Remote MySQL).

0. **Before** -- everything to deploy is committed (the deploy sends commits, not the working tree); `npm run build` (the script uploads this PC's `public/js` -- built from the committed code). Don't deploy dev's `.env`: it may be in the commits (still tracked), the script keeps production's anyway.
1. **Dry run** -- `php artisan tinker --execute="$apply = false; require '<repo>\database\scripts\protocol_prod_deploy.php';"`: production's commit -> this PC's HEAD (commits + files), .env note, composer yes/no, public/js build time, production files that would be overwritten (same content incl. CRLF-only differences = fine; real conflicts = refused), pending migrations. Show it to the user and ask.
2. **Apply** (after the yes) -- same with `$apply = true`:
   - production `.env` copied to `~/murano_deploy/env.production` (md5 checked), `php artisan down`;
   - git bundle of `<production commit>..HEAD` -> SFTP -> `git fetch` + `git merge --ff-only` in `public_html` (refused if production isn't an ancestor);
   - `public/js` zipped here -> unzip into `public/js.new` -> swap (previous kept as `public/js.prev`);
   - `composer install` when `composer.lock` changed;
   - migrations through `protocol_prod_migrate.php` (mysqldump backup to `KT-TOOLS/database/production/` first);
   - `optimize:clear`; in a `finally`: production `.env` put back (md5 checked) and `php artisan up` -- the site never stays in maintenance;
   - PHP workers restarted (`pkill -u <user> lsphp`, by process name), 4 checks of `/login` (expect 200).
2b. **Dev data -> production** (user's rule, 2026-10-18: "actualizar" covers dev's changes) -- `php artisan tinker --execute="$apply = false; require '<repo>\database\scripts\protocol_prod_push.php';"`: what was created / changed in dev since the last Baja Prod (`KT-TOOLS/database/production/last_sync.json`, or `$since`): new rows (same id, only when free in production), changed rows (only when production didn't change them too), pivot rows of the rows sent, and dev files in `public/files` newer than that point that production lacks. Conflicts (id taken, changed on both sides) are listed and NOT sent -- ask the user. Show the dry run, get the yes, then `$apply = true` (production mysqldump backup first, one transaction). Never sends migrations, kt_logs, translations / permissions / menu_positions, sessions, queues, login history.
3. **Verify** -- HEAD on the server = this PC's, `git status` there shows only `.env`, `public/js` is the new build, the site answers 200; report to the user.
4. **Undo** -- `$rollback = true`: code back to the commit before the last deploy (`~/murano_deploy/last_deploy.json`) and `public/js.prev` restored. The database is NOT rolled back (its backup path is in last_deploy.json).

Remote commands are quoted for bash with `$q` (single quotes) -- never `escapeshellarg` for them (on Windows it uses double quotes and breaks bash). Local git uses `escapeshellarg`.

After switching to Sube Prod: disable the Hostinger Git deploy in hPanel and delete `domains/murano.kcoltech.com/hbuilds` (it held ~300k files).
