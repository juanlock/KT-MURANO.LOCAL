---
description: "Protocol Mañanitas -- session bootstrap: catch up kt_logs via Kelsy if needed, digest project history into memory so it's never re-analyzed from raw rows again, and surface the most recurrent changes. Also triggers on \"Claude/Jarvis, run protocol Mañanitas\"."
---

Run Protocol Mañanitas -- a startup/bootstrap routine meant to run once at the start of a work session, on whichever computer this checkout is open on. Unlike Kelsy/Memorex (which react to what happened *in this conversation*), Mañanitas looks at the *whole* `kt_logs` history available on this machine.

**Important scoping note, same as Kelsy/Memorex:** each machine's `.env` points at its own local `dbmurano` database by default, and there's no known *automated* sync for `kt_logs` across machines. Don't assume this machine's `kt_logs` is either definitely current or definitely behind; step 1 just needs to confirm THIS conversation's activity is reflected, whatever the table's starting state turns out to be.

1. **Catch up the log.** Verify `kt_logs` exists on this machine's database (create it per Kelsy's step 1 if not) and check whether this conversation's activity is already reflected in it. If anything is missing, run Protocol Kelsy's full logic (see `.claude/commands/kelsy.md`) to bring it current before continuing -- steps 2-3 below are only as good as the data they read.

2. **Digest project history into memory, so it's never re-analyzed from raw rows again.** Read every `kt_logs` row available in this machine's database (`summary`, `category`, `files_modified`, `changes_detail`, `company_context`, `log_date`). Create or overwrite a single memory file -- `reference_project_activity_digest.md`, type `project` -- with a condensed, narrative summary: what's been built, the major fixes/features roughly in chronological order, the architectural patterns established, and anything a future session would otherwise have to re-derive by reading every row from scratch. Overwrite the file's content each run rather than appending -- it should always reflect the FULL current history in digest form, not grow into a second log. This step's entire point is to save future re-analysis, so the output belongs in memory, not as more `kt_logs` rows.

3. **Surface the most recurrent changes.** From the same data, identify: the most common `category` values, the files that show up most often in `files_modified`, and any recurring theme visible in `changes_detail` (e.g. a class of bug that keeps reappearing across modules). Report this as a short ranked list directly to the user, and fold a one-line version of it into the digest memory file from step 2 under a "Recurring patterns" heading, so a future session immediately knows what tends to break in this codebase without re-deriving it.

Location for kt_logs rows, if Kelsy's step 1 needs to ask: reuse whatever's already established this conversation; otherwise ask (ThinkBook1 / Home / new).

Why this exists: the user (jlock) wants a single startup command, runnable from any machine with this checkout, that keeps the activity log current, avoids re-deriving project context from scratch every session, and highlights recurring problem areas.

Ported from KT-BILLING.LOCAL's Protocol Mañanitas as part of the kt_log module migration (billing is read-only source; this copy is Murano's own). See CLAUDE.md protocol "preparate" for the billing/murano relationship.
