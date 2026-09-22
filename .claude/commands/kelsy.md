---
description: "Protocol Kelsy -- append this conversation's activity to the kt_logs table. Also triggers on the spoken phrase \"Claude/Jarvis, execute/run protocol Kelsy\"."
---

Run Protocol Kelsy:

1. Verify the `kt_logs` table exists in whichever database this checkout is currently pointed at (check `.env` / the active connection, not an assumption -- should be `dbmurano` per CLAUDE.md). If it's missing, recreate it by running `database/migrations/2026_09_22_120000_create_kt_logs_table.php` (`php artisan migrate`).
2. Append one `kt_logs` row per distinct request made since the last Protocol Kelsy run (or since the start of the conversation if it's never been run this session). Don't re-log a request that's already in there. Columns:
   - `log_date` (today), `claude_user` (the user's email if known, else ask), `location`, `request_user` (verbatim ask), `claude_understand` (your interpretation), `chat_transcript` (condensed account of what happened).
   - `files_modified` -- every file touched, one per line or comma-separated, not just a couple of highlights.
   - `actions` -- a specific, detailed account of what you actually did, in enough depth that someone who wasn't watching could follow it: which functions/methods/endpoints were involved, what was diagnosed vs. implemented vs. verified, any commands run. Not a one-line gloss -- several sentences or a short list is expected.
   - `changes_detail` -- a granular, itemized changelog of the actual code/data changes: for each file (or logical group of files), what specifically changed (old behavior -> new behavior, exact function/method names, schema/column changes, config changes). This is the field someone would read to understand a diff without opening the files. Distinct from `actions` (what you did) and `chat_transcript` (the narrative) -- this one is the changelog.
   - `summary` (short, one line), `category` (bug-fix / feature / security / cleanup / etc.), `company_context` (which company's data was involved, or "All"/"N/A" -- see the SuperAdmin/Admin multicompany rule in CLAUDE.md).
   - `status` -- **Completed** by default. Use **In Progress** if the request is still open/ongoing at the time of logging, or **Blocked** if it stalled on something outside your control (missing info, a failing dependency, waiting on the user). This is what Protocol Preparate's closing report reads to show the last request's state -- infer it, don't ask.
   - `done` -- the **verbatim closing message** sent to the user for this request (the actual text of your final reply, not a re-summary of it, and not a re-typed reconstruction -- copy it exactly, including any headers/tables/formatting it had). Distinct from `summary` (one line) and `chat_transcript` (a condensed third-person narrative of what happened) -- this is what the user actually read at the end of the turn. If a single Kelsy run is logging several requests from the conversation at once, each row's `done` is that specific request's own closing message, not the whole conversation's.
3. The ONLY thing to ask the user about is `location` -- offer **ThinkBook1**, **Home**, or a new location, via a quick question. Don't ask for confirmation on anything else in this protocol.

Why this exists: the user (jlock) wants to trigger a full activity-log append with one short phrase instead of re-explaining the process each time, from any machine that has this repo checked out.

Ported from KT-BILLING.LOCAL's Protocol Kelsy as part of the kt_log module migration (billing is read-only source; this copy is Murano's own, adapted for `dbmurano` and this repo's migration). See CLAUDE.md protocol "preparate" for the billing/murano relationship.
