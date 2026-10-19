<?php

/*
 * Protocol Wake-up -- the start-of-day report (user's rules, 2026-10-19):
 *   1. what this run did ($wakeSummary, lines set by the protocol before the
 *      require: git pull, backup file, production code / data status...);
 *   2. the CURRENT CONVENTIONS -- read live from CLAUDE.md (Development
 *      Standards, Frontend Conventions, Hard Rules), so the report always
 *      shows the rules as they are today, never a stale copy;
 *   3. the LAST 5 CHANGES from kt_logs, newest first, each with its date / time
 *      and origin (the PC it was made on + who made it).
 * Writes KT-TOOLS/Report/kt_murano_wakeup_<date>.html (git-tracked, one per
 * day: a re-run the same day overwrites it) and prints its path.
 *
 *   php artisan tinker --execute="$wakeSummary = ['...', '...']; require '<repo>\database\scripts\protocol_wakeup_report.php';"
 */

use Illuminate\Support\Facades\DB;

$wakeSummary = isset($wakeSummary) ? (array) $wakeSummary : [];
$e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
// Tiny Markdown subset: `code`, **bold**.
$inline = fn ($s) => preg_replace(['/`([^`]+)`/', '/\*\*(.+?)\*\*/'], ['<code>$1</code>', '<b>$1</b>'], $e($s));

// ---------------- conventions from CLAUDE.md ----------------
$md = (string) @file_get_contents(base_path('CLAUDE.md'));
$wanted = ['Development Standards', 'Frontend Conventions', 'Hard Rules'];
$sections = [];
$current = null;
foreach (preg_split('/\R/', $md) as $line) {
    if (preg_match('/^## (.+)$/', $line, $m)) {
        $current = in_array(trim($m[1]), $wanted, true) ? trim($m[1]) : null;
        if ($current) $sections[$current] = [];
        continue;
    }
    if ($current) $sections[$current][] = $line;
}
$render = function (array $lines) use ($inline) {
    // Group continuation lines into their bullet / paragraph.
    $html = '';
    $item = null;
    $flush = function () use (&$item, &$html, $inline) {
        if ($item === null) return;
        $html .= $item['type'] === 'li' ? '<li>'.$inline($item['text']).'</li>' : '<p>'.$inline($item['text']).'</p>';
        $item = null;
    };
    $inList = false;
    foreach ($lines as $l) {
        if (preg_match('/^### (.+)$/', $l, $m)) {
            $flush(); if ($inList) { $html .= '</ul>'; $inList = false; }
            $html .= '<h3>'.$inline($m[1]).'</h3>';
        } elseif (preg_match('/^- (.+)$/', $l, $m)) {
            $flush(); if (! $inList) { $html .= '<ul>'; $inList = true; }
            $item = ['type' => 'li', 'text' => $m[1]];
        } elseif (trim($l) === '') {
            $flush();
        } elseif ($item !== null) {
            $item['text'] .= ' '.trim($l);
        } else {
            if ($inList && ! preg_match('/^\s/', $l)) { $html .= '</ul>'; $inList = false; }
            $item = ['type' => 'p', 'text' => trim($l)];
        }
    }
    $flush();
    if ($inList) $html .= '</ul>';

    return $html;
};

// ---------------- last 5 changes ----------------
$rows = DB::table('kt_logs')->orderByDesc('created_at')->orderByDesc('id')->limit(5)
    ->get(['id', 'created_at', 'log_date', 'location', 'claude_user', 'category', 'status', 'summary', 'request_user', 'files_modified']);

$host = gethostname() ?: 'unknown-host';
$now = now();
$statusColor = ['Completed' => '#157a4b', 'In Progress' => '#8a5a00', 'Blocked' => '#b42318'];

$html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Wake-up '.$now->format('Y-m-d').'</title><style>
body{font:14px/1.55 "Segoe UI",system-ui,sans-serif;margin:0;background:#f6f6fb;color:#1d1b33}
.wrap{max-width:1100px;margin:0 auto;padding:24px 16px 48px}
h1{font-size:24px;margin:0 0 4px}h2{font-size:18px;margin:28px 0 10px;padding-bottom:6px;border-bottom:2px solid #e3e2ef}h3{font-size:15px;margin:16px 0 6px;color:#5b4fe0}
.muted{color:#5d5a78}.card{background:#fff;border:1px solid #e3e2ef;border-radius:10px;padding:14px 16px;margin:10px 0}
ul{margin:4px 0 8px;padding-left:20px}li{margin:3px 0}code{background:#eeecfd;padding:0 5px;border-radius:4px;font-size:12.5px}
table{border-collapse:collapse;width:100%;background:#fff}th,td{border:1px solid #e3e2ef;padding:7px 9px;text-align:left;vertical-align:top;font-size:13px}th{background:#eeecfd}
.pill{display:inline-block;padding:1px 9px;border-radius:999px;font-size:12px;font-weight:600;color:#fff}
details summary{cursor:pointer;font-weight:600}
</style></head><body><div class="wrap">';
$html .= '<h1>Wake-up — '.$e($host).'</h1><div class="muted">'.$now->format('l, F j, Y · g:i A').' · Murano (dbmurano)</div>';

$html .= '<h2>This morning</h2><div class="card">'.($wakeSummary ? '<ul>'.implode('', array_map(fn ($l) => '<li>'.$inline($l).'</li>', $wakeSummary)).'</ul>' : '<span class="muted">No summary passed.</span>').'</div>';

$html .= '<h2>Last 5 changes</h2><table><tr><th>#</th><th>Date</th><th>Origin</th><th>Change</th><th>Status</th></tr>';
foreach ($rows as $r) {
    $c = $statusColor[$r->status] ?? '#5d5a78';
    $html .= '<tr><td>'.$e($r->id).'</td><td>'.$e(\Carbon\Carbon::parse($r->created_at)->format('Y-m-d g:i A')).'</td><td>'.$e($r->location ?: '-').'<br><span class="muted">'.$e($r->claude_user ?: '').'</span></td>'
        .'<td><b>'.$e($r->category).'</b> — '.$e($r->summary).'<details><summary class="muted">Request</summary>'.$e($r->request_user).'</details></td>'
        .'<td><span class="pill" style="background:'.$c.'">'.$e($r->status ?: '-').'</span></td></tr>';
}
$html .= '</table>';

$html .= '<h2>Current conventions</h2><p class="muted">Read now from <code>CLAUDE.md</code> — the rules every change follows.</p>';
foreach ($wanted as $name) {
    if (! isset($sections[$name])) continue;
    $html .= '<details open class="card"><summary>'.$e($name).'</summary>'.$render($sections[$name]).'</details>';
}
$html .= '</div></body></html>';

$dir = base_path('KT-TOOLS'.DIRECTORY_SEPARATOR.'Report');
if (! is_dir($dir)) mkdir($dir, 0775, true);
$path = $dir.DIRECTORY_SEPARATOR.'kt_murano_wakeup_'.$now->format('Y-m-d').'.html';
file_put_contents($path, $html);
echo 'Wake-up report: '.$path.PHP_EOL;
