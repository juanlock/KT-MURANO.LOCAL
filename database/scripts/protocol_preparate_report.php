<?php

// Protocol Preparate -- generates a short HTML snapshot of the LAST 2
// kt_logs requests (what was asked, what changed, and status). Unlike
// Memorex (full history table), this is a small, focused status card meant
// to close out a Preparate run.
// Run via:
// php artisan tinker --execute="require 'database/scripts/protocol_preparate_report.php';"
// Then open the printed path (e.g. via `Start-Process <path>` on Windows).
// Another count (e.g. "report for the last 3 requests"): set $reportLimit
// first -- php artisan tinker --execute="$reportLimit = 3; require '...';"
// A whole day's requests (A Dormir's daily report): set $reportDate
// ('Y-m-d') instead -- php artisan tinker --execute="$reportDate = '2026-09-30'; require '...';"
//
// Output: KT-TOOLS/Report/ (git-tracked, so every programmer gets them),
// one file per day per report -- re-running the same report the same day
// overwrites that day's file, older days are kept:
//   kt_murano_preparate_<date>.html     (last 2 requests)
//   kt_murano_last<N>_<date>.html       ($reportLimit = N)
//   kt_murano_daily_<date>.html         ($reportDate: every request of that day)

use App\Models\KtLog;

$reportDate = isset($reportDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $reportDate) ? (string) $reportDate : null;
$reportLimit = isset($reportLimit) ? max(1, min(50, (int) $reportLimit)) : 2;
if ($reportDate) {
    $rows = KtLog::whereDate('log_date', $reportDate)->orderBy('id')->get();
    $reportTitle = "Daily report -- {$reportDate}";
    $reportScope = "{$rows->count()} request(s) logged on {$reportDate}";
    $reportFile = "kt_murano_daily_{$reportDate}.html";
} else {
    $rows = KtLog::orderByDesc('id')->take($reportLimit)->get();
    $reportTitle = $reportLimit === 2 ? 'Protocol Preparate' : "Last {$reportLimit} requests";
    $reportScope = "last {$rows->count()} request(s)";
    $reportFile = ($reportLimit === 2 ? 'kt_murano_preparate_' : "kt_murano_last{$reportLimit}_").now()->format('Y-m-d').'.html';
}

function esc($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function statusColor($status) {
    return match (strtolower((string) $status)) {
        'completed' => '#0f9d58',
        'in progress' => '#f4b400',
        'blocked', 'failed' => '#db4437',
        default => '#5f6368',
    };
}

function renderCard($row) {
    $dateTime = esc($row->log_date?->format('Y-m-d')).' '.esc($row->created_at?->format('H:i'));
    $statusColor = statusColor($row->status);
    $status = esc($row->status);
    $request = esc($row->summary ?: $row->request_user);
    $claudeUser = esc($row->claude_user);
    $location = esc($row->location);
    $category = esc($row->category);
    $companyContext = esc($row->company_context);
    $changesDetail = esc($row->changes_detail);
    $actions = esc($row->actions);
    return <<<HTML
    <div class="card">
      <div class="row"><span class="label">Status</span><span class="badge" style="background:{$statusColor}">{$status}</span></div>
      <div class="row"><span class="label">Request</span><span>{$request}</span></div>
      <div class="row"><span class="label">Source</span><span>{$claudeUser} &middot; {$location} &middot; {$dateTime}</span></div>
      <div class="row"><span class="label">Category</span><span>{$category}</span></div>
      <div class="row"><span class="label">Company</span><span>{$companyContext}</span></div>
      <div class="block"><div class="label">Changes Made</div><div class="text">{$changesDetail}</div></div>
      <div class="block"><div class="label">Actions</div><div class="text">{$actions}</div></div>
    </div>
    HTML;
}

$generatedAt = now()->format('Y-m-d g:i A');
$generatedHost = esc(gethostname());

if ($rows->isEmpty()) {
    $body = $reportDate
        ? '<p class="empty">No kt_logs rows logged on this day.</p>'
        : '<p class="empty">No kt_logs rows found yet -- run Protocol Kelsy first.</p>';
} else {
    $body = $rows->map(fn ($row) => renderCard($row))->implode("\n");
}

$html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>KT-Murano -- {$reportTitle}</title>
<style>
  body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 24px; background: #f7f8fa; color: #1a1a1a; }
  h1 { font-size: 18px; margin-bottom: 4px; }
  .meta { color: #666; font-size: 12px; margin-bottom: 16px; }
  .card { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.08); padding: 16px 20px; max-width: 720px; margin-bottom: 16px; }
  .row { display: flex; gap: 10px; align-items: center; padding: 6px 0; border-bottom: 1px solid #f0f0f0; font-size: 13.5px; }
  .row .label { width: 100px; flex-shrink: 0; color: #666; font-weight: 600; }
  .badge { color: #fff; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
  .block { padding: 10px 0; }
  .block .label { color: #666; font-weight: 600; font-size: 12.5px; margin-bottom: 4px; }
  .block .text { font-size: 13px; white-space: pre-wrap; }
  .empty { color: #666; }
</style>
</head>
<body>
  <h1>KT-Murano -- {$reportTitle}</h1>
  <div class="meta">Generated {$generatedAt} on {$generatedHost} -- {$reportScope}</div>
  {$body}
</body>
</html>
HTML;

$outDir = base_path('KT-TOOLS'.DIRECTORY_SEPARATOR.'Report');
if (! is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}
$outPath = $outDir.DIRECTORY_SEPARATOR.$reportFile;
file_put_contents($outPath, $html);

echo "Report written to: {$outPath}\n";
