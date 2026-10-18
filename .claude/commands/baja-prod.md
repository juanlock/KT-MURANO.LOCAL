---
description: "Protocol Baja Prod -- brings production down to dev on demand: the files created in production's public/files (SFTP) and its new / changed records (sales, customers, emails...) into dbmurano. Dry run first, then apply. Same as Pa Llevar's step 000, without the rest of the close-out. Triggers on \"Jarvis, run protocolo baja prod\" / \"protocolo baja prod\" / \"baja prod\"."
---

Run Protocol Baja Prod ("bring production down") -- production -> dev only. Production is only READ. It is Pa Llevar's step 000 on its own (user's request, 2026-10-18), for when the user wants dev up to date with production now (e.g. to test with a new company / sale just created there).

Needs, on this machine (git-ignored, per machine -- never ask for passwords; if a file is missing say which and stop that part):
- `.env.production-ssh` (SSH: files) and `.env.production-db` (database: records). The production database also needs this PC's public IP allowed in hPanel -> Databases -> Remote MySQL (`Access denied ... @'<ip>'` = add the IP).

1. **Files** -- `php artisan tinker --execute="$apply = false; require '<repo>\database\scripts\protocol_prod_files.php';"` (dry run: new / changed files per folder; CRLF / LF differences of text files are not changes; Git LFS pointers in production are reported, never downloaded). Then `$apply = true` to download them into `public/files` (a dev copy it replaces is saved first in `KT-TOOLS/database/production/files_replaced_<stamp>/`). Nothing is deleted on either side.
2. **Records** -- `php artisan tinker --execute="$apply = false; require '<repo>\database\scripts\protocol_prod_pull.php';"` (dry run: per table, new rows in production, rows production changed, rows where dev is newer -- kept -- and rows only in dev). Show the summary; rows "only in dev" in business tables (sales, payments, products, companies...) would clash with production's next ids -- point them out. Then `$apply = true`: copied into `dbmurano` in ONE transaction (the dev rows it replaces saved first to `KT-TOOLS/database/production/dev_before_prod_pull_<stamp>.json`). Never touches `migrations`, `kt_logs` or the queue / cache / session tables.
3. **Report** to the user: files downloaded (count, MB, folders), rows pulled per table (new / updated), anything kept from dev or only in dev, and the backup paths. Log the request in `kt_logs` (Kelsy rules).

Not included: Copia BD and the commit -- Pa Llevar does those (and starts with this same step). Dev -> production goes only by migrations, after the monthly code deploy (Pa Llevar step 1c).
