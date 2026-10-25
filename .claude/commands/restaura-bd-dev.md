---
description: "Protocol Restaura BD Dev -- restore THIS PC's local dev database (dbmurano) from a Murano backup: shows the latest backup (date, origin PC, tag, size) and asks whether to restore from it (or another of the latest ones); the current dev database is backed up first (tag prerestore). Never touches production. Triggers on \"Jarvis, restaura bd dev\" / \"protocolo restaura bd dev\" / \"restaurar la base de desarrollo\"."
---

Run Protocol Restaura BD Dev (user's request, 2026-10-25). The normal way to align a PC is **Wake-up** (a fresh copy of production); this protocol is for when the user wants the dev database of a backup instead (e.g. production unreachable from this PC, or going back to a known state). In this order; stop and report if a step fails.

0. **Which PC** -- run `hostname` (don't ask): the Kelsy location (`KT-THUNDERA` -> Thundera, `KTOPTIMUM_NB` -> Optimum, any other -> Home).

1. **Latest backups** -- if the git tree may be behind, say so (the backups travel with git: `KT-TOOLS/database/`, LFS; GitHub Desktop "Fetch origin" + "Pull" first). Then `php artisan murano:restore-dev --list` (newest first: date and time from the file name, tag -- copiabd / wakeup / pallevar / adormir / prerestore --, origin PC, size, the office_emails 1/2/3 parts).

2. **Ask** (AskUserQuestion) with the first one as the recommended option: "Restore the dev database of <this PC> from <date time> (<tag>, PC <origin>, <size>)?" -- options: **Restore from it**, **Pick another** (then offer the next 3-4 of the list), **Cancel**. Mention: everything in this PC's dev database is replaced (it is backed up first, tag `prerestore`), production is not touched, and Wake-up is the usual way to get production's current data.

3. **Restore** -- on yes: `php artisan murano:restore-dev "<zip path>" --yes` (long timeout). It: backs up the current dev database (`KT-TOOLS/database/<PC>/..._prerestore.zip`), drops every local table, imports the main dump then office_emails 1, 2, 3 in order, runs this PC's migrations (the code may be newer than the backup) and clears the caches. A failed import: report it and point to the prerestore backup (restoring it = this same protocol, picking it).

4. **After** -- `npm run build` only if `public/js` is missing; Kelsy row (`.claude/commands/kelsy.md`) with the backup restored, its date / origin and the prerestore file.

5. **Report** in the chat: backup restored (date, tag, origin PC), tables imported, migrations run, the prerestore backup file, and a reminder that the next Wake-up replaces this dev database with production again.
