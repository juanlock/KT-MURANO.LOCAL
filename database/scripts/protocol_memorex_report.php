<?php

// Protocol Memorex -- generates a short, concise HTML activity report from
// kt_logs (source/location, date_time, details, actions, changes made) and
// saves it to KT-TOOLS/Report/kt_murano_memorex_<date>.html (git-tracked,
// shared with every programmer; one file per day, re-runs overwrite that
// day's). Run via: php artisan tinker --execute="require 'database/scripts/protocol_memorex_report.php';"
// Then open the printed path (e.g. via `Start-Process <path>` on Windows).
//
// Ported from KT-BILLING.LOCAL as part of the kt_log module migration.

use App\Models\KtLog;

// Newest first (user's rule, 2026-10-05): by the date/time each request was
// logged (created_at), then id.
$rows = KtLog::orderByDesc('created_at')->orderByDesc('id')->get();

function esc($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

$tableRows = '';
foreach ($rows as $r) {
    $dateTime = esc($r->created_at?->format('Y-m-d H:i'));
    $tableRows .= '<tr>'
        .'<td>'.esc($r->claude_user).'</td>'
        .'<td>'.esc($r->location).'</td>'
        .'<td class="nowrap">'.$dateTime.'</td>'
        .'<td>'.esc($r->summary ?: $r->request_user).'</td>'
        .'<td>'.esc($r->actions).'</td>'
        .'<td>'.esc($r->changes_detail).'</td>'
        .'</tr>';
}

$generatedAt = now()->format('Y-m-d H:i');
$totalRows = $rows->count();

$html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>KT-Murano Activity Report</title>
<style>
  body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 24px; background: #f7f8fa; color: #1a1a1a; }
  h1 { font-size: 18px; margin-bottom: 4px; }
  .meta { color: #666; font-size: 12px; margin-bottom: 16px; }
  table { border-collapse: collapse; width: 100%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
  th, td { border: 1px solid #e5e7eb; padding: 8px 10px; font-size: 12.5px; vertical-align: top; text-align: left; }
  th { background: #1a56db; color: #fff; position: sticky; top: 0; }
  tr:nth-child(even) { background: #fafbfc; }
  td.nowrap { white-space: nowrap; }
  td:nth-child(1) { font-weight: 600; white-space: nowrap; }
  td:nth-child(2) { white-space: nowrap; }
</style>
</head>
<body>
  <h1>KT-Murano -- Claude Code Activity Report</h1>
  <div class="meta">Generated {$generatedAt} -- {$totalRows} logged entries (source: kt_logs)</div>
  <table>
    <thead>
      <tr><th>Source</th><th>Location</th><th>Date/Time</th><th>Details</th><th>Actions</th><th>Changes Made</th></tr>
    </thead>
    <tbody>
      {$tableRows}
    </tbody>
  </table>
</body>
</html>
HTML;

$outDir = base_path('KT-TOOLS'.DIRECTORY_SEPARATOR.'Report');
if (! is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}
$outPath = $outDir.DIRECTORY_SEPARATOR.'kt_murano_memorex_'.now()->format('Y-m-d').'.html';
file_put_contents($outPath, $html);

echo "Report written to: {$outPath}\n";
echo "Rows: {$totalRows}\n";
