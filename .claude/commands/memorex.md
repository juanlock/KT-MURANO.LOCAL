---
description: "Protocol Memorex -- generate a short HTML activity report from kt_logs and open it. Also triggers on \"Claude/Jarvis, run protocol Memorex\"."
---

Run Protocol Memorex:

1. First run Protocol Kelsy's logic silently (see `.claude/commands/kelsy.md`) if this conversation has requests not yet appended to `kt_logs` -- the report is only as good as the log being current. Only ask about `location` if it's genuinely ambiguous and hasn't already been established this conversation.
2. Run `database/scripts/protocol_memorex_report.php` via tinker, using the file's **absolute path** (a bare relative `require` inside `php artisan tinker --execute="..."` fails with "Failed opening required" -- psysh's RequirePass doesn't resolve it against the project root). Example (adjust the drive/path to wherever this checkout actually lives on this machine):
   `php artisan tinker --execute="require '<absolute-path-to-repo>\database\scripts\protocol_memorex_report.php';"`
   It queries every `kt_logs` row and writes a short, concise HTML table to `%TEMP%/kt_murano_activity_report.html` (or the platform's temp dir) with columns: **Source** (claude_user), **Location**, **Date/Time**, **Details** (short -- uses the `summary` column), **Actions**, **Changes Made** (the `changes_detail` column).
3. Open it: in Windows PowerShell run `Start-Process "<path>"` (**not** `start "" "<path>"` -- that's cmd.exe syntax and fails under PowerShell's `start` alias for `Start-Process`, which parses `""` as an empty `-FilePath`) so it opens in the default browser. Report the path either way in case auto-open doesn't work in this environment.

Why this exists: the user (jlock) wants a quick visual activity report without reading raw `kt_logs` rows, runnable from any machine that has this repo checked out.

Ported from KT-BILLING.LOCAL's Protocol Memorex as part of the kt_log module migration (billing is read-only source; this copy is Murano's own -- report writes to `kt_murano_activity_report.html`, a distinct filename from billing's `kt_billing_activity_report.html` so the two never collide on a machine that has both repos checked out). See CLAUDE.md protocol "preparate" for the billing/murano relationship.
