<?php

// Protocol Preparate -- HTML report of kt_logs requests, one consistent
// layout for every mode:
//   1. header: scope, generated when/where, totals by status;
//   2. index table: #, date requested, module, request (summary), status;
//   3. requests GROUPED BY MODULE (POS, Bookings, Sales, Settings, ...),
//      decided from the files each request touched (files_modified) and, when
//      it touched none, from its wording. Each request card shows:
//        date requested · location · category · company · status,
//        Request (verbatim), Solution (the closing message, `done`),
//        What changed (`changes_detail`), Files affected (by module).
//
// Run via (absolute path -- see preparate.md):
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
// Newest first (user's rule, 2026-10-05): by the date/time each request was
// logged (created_at), then id -- in the index, the module sections and the
// cards inside each section.
if ($reportDate) {
    $rows = KtLog::whereDate('log_date', $reportDate)->orderByDesc('created_at')->orderByDesc('id')->get();
    $reportTitle = "Daily report -- {$reportDate}";
    $reportScope = "{$rows->count()} request(s) logged on {$reportDate}";
    $reportFile = "kt_murano_daily_{$reportDate}.html";
} else {
    $rows = KtLog::orderByDesc('created_at')->orderByDesc('id')->take($reportLimit)->get();
    $reportTitle = $reportLimit === 2 ? 'Protocol Preparate' : "Last {$reportLimit} requests";
    $reportScope = "last {$rows->count()} request(s)";
    $reportFile = ($reportLimit === 2 ? 'kt_murano_preparate_' : "kt_murano_last{$reportLimit}_").now()->format('Y-m-d').'.html';
}

$e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

/* ------------------------------------------------------------------ modules
 * Path / word patterns -> module, first match wins (order matters: the more
 * specific modules come before the broad ones). */
$MODULES = [
    'Protocols & tooling' => ['~^\.claude/~i', '~database/scripts/~i', '~KT-TOOLS~i', '~CLAUDE\.md~i', '~protocol~i'],
    'POS' => ['~/pos/~i', '~pos-compat~i', '~Pos[A-Z]\w*\.(vue|php)~', '~PosController|PosPinController|CashRegister~i', '~pos_settings|PosSettings|PosReceipt|posReceipt~i'],
    'Bookings' => ['~booking~i'],
    'Invoice PDF' => ['~pdf~i'],
    'Roles & permissions' => ['~permission~i', '~/Policies/~i', '~Role~'],
    'Menu' => ['~menu~i'],
    'Sales' => ['~/sales/|SalesController|PaymentSales|sale_return|SalesReturn~i'],
    'Purchases' => ['~purchase~i'],
    'Quotations' => ['~quotation~i'],
    'Products & services' => ['~product|VehicleFitment|vehicle_fitment|categor|brand|unit~i'],
    'Locations' => ['~warehouse|location~i'],
    'Users & customers' => ['~/people/|UserController|UserForm|Client|customer|supplier|provider|LinkedUser|employee~i'],
    'Online store' => ['~/store/|Store[A-Z]|storefront~i'],
    'Reports' => ['~/reports/|Report\.vue|ReportController~i'],
    'Translations & wording' => ['~translation|/lang/|seeders/translations|i18n~i'],
    'Settings' => ['~settings|SettingsController|Setting\.php~i'],
    'Database' => ['~database/migrations|\.sql~i'],
];
$moduleOf = function (string $text) use ($MODULES): ?string {
    foreach ($MODULES as $name => $patterns) {
        foreach ($patterns as $re) {
            if (preg_match($re, $text)) {
                return $name;
            }
        }
    }

    return null;
};

/** files_modified -> list of file entries (one per line / comma / semicolon). */
$filesOf = function ($row): array {
    $raw = (string) $row->files_modified;
    if (trim($raw) === '') {
        return [];
    }
    $parts = preg_split('/\r?\n|;\s*/', $raw);

    return array_values(array_filter(array_map('trim', $parts), fn ($f) => $f !== '' && strlen($f) < 400));
};

// Requests whose SUMMARY says what kind of change it is, whatever files it touched.
$SUMMARY_MODULE = [
    '~\b(renamed?|wording|relabel)~i' => 'Translations & wording',
    '~\b(protocol|kt_logs|report for|status report)~i' => 'Protocols & tooling',
];
/** Module named most often in a text (fallback when no file says it). */
$moduleByText = function (string $text) use ($MODULES): ?string {
    $best = null;
    $hits = 0;
    foreach ($MODULES as $name => $patterns) {
        $n = 0;
        foreach ($patterns as $re) {
            $n += preg_match_all($re, $text);
        }
        if ($n > $hits) {
            [$best, $hits] = [$name, $n];
        }
    }

    return $best;
};

/** Primary module (most files) + every module touched, for one request. */
$classify = function ($row) use ($filesOf, $moduleOf, $moduleByText, $SUMMARY_MODULE): array {
    $count = [];
    $byModule = [];
    foreach ($filesOf($row) as $f) {
        // migrations / seeders are read by their name (what they change), not as "Database"
        $m = $moduleOf($f) ?? 'Other';
        $count[$m] = ($count[$m] ?? 0) + 1;
        $byModule[$m][] = $f;
    }
    // Generic buckets only win when nothing more specific was touched.
    $weak = ['Database', 'Settings', 'Translations & wording', 'Other'];
    $strong = array_diff_key($count, array_flip($weak));
    arsort($strong);
    arsort($count);
    $primary = array_key_first($strong) ?? array_key_first($count);
    foreach ($SUMMARY_MODULE as $re => $name) {
        if (preg_match($re, (string) $row->summary)) {
            $primary = $name;
            break;
        }
    }
    if (! $primary || $primary === 'Other') {
        $primary = $moduleByText((string) $row->summary) ?? 'General / support';
    }

    return [$primary, $byModule];
};

/* ------------------------------------------------- markdown-lite (done) */
$md = function (string $text) use ($e): string {
    $inline = function (string $s) use ($e) {
        $s = $e($s);
        $s = preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', $s);
        $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);

        return $s;
    };
    $lines = preg_split('/\r?\n/', trim($text));
    $html = '';
    $list = null;   // 'ul' | 'ol'
    $table = [];
    $flushTable = function () use (&$table, &$html, $inline) {
        if (! $table) {
            return;
        }
        $html .= '<table class="mdt">';
        foreach ($table as $i => $cells) {
            $tag = $i === 0 ? 'th' : 'td';
            $html .= '<tr>'.implode('', array_map(fn ($c) => "<{$tag}>".$inline(trim($c))."</{$tag}>", $cells)).'</tr>';
        }
        $html .= '</table>';
        $table = [];
    };
    foreach ($lines as $line) {
        $t = rtrim($line);
        if (preg_match('/^\s*\|(.+)\|\s*$/', $t, $m)) {
            if ($list) { $html .= "</{$list}>"; $list = null; }
            if (! preg_match('/^[\s|:-]+$/', $m[1])) {
                $table[] = explode('|', $m[1]);
            }
            continue;
        }
        $flushTable();
        if (preg_match('/^\s*[-*]\s+(.*)$/', $t, $m) || preg_match('/^\s*\d+[.)]\s+(.*)$/', $t, $m2)) {
            $type = isset($m[1]) && preg_match('/^\s*[-*]\s+/', $t) ? 'ul' : 'ol';
            $item = $type === 'ul' ? $m[1] : $m2[1];
            if ($list !== $type) {
                if ($list) { $html .= "</{$list}>"; }
                $html .= "<{$type}>";
                $list = $type;
            }
            $html .= '<li>'.$inline($item).'</li>';
            continue;
        }
        if ($list) { $html .= "</{$list}>"; $list = null; }
        $html .= trim($t) === '' ? '' : '<p>'.$inline($t).'</p>';
    }
    $flushTable();
    if ($list) { $html .= "</{$list}>"; }

    return $html;
};

$statusColor = fn ($s) => match (strtolower((string) $s)) {
    'completed' => '#0f9d58',
    'in progress' => '#f4b400',
    'blocked', 'failed' => '#db4437',
    default => '#5f6368',
};
$badge = fn ($s) => '<span class="badge" style="background:'.$statusColor($s).'">'.$e($s ?: '—').'</span>';
$when = function ($row) {
    $d = $row->log_date ? \Carbon\Carbon::parse($row->log_date)->format('D, M j, Y') : '';
    $t = $row->created_at ? $row->created_at->format('g:i A') : '';

    return trim($d.($t ? ' · '.$t : ''));
};

/* ------------------------------------------------------------- build */
$items = $rows->map(function ($row) use ($classify) {
    [$module, $files] = $classify($row);

    return ['row' => $row, 'module' => $module, 'files' => $files];
});
// Sections in the order of their newest request (rows are already newest first).
$groups = $items->groupBy('module');
$statusCounts = $rows->groupBy(fn ($r) => $r->status ?: '—')->map->count();

$generatedAt = now()->format('D, M j, Y g:i A');
$generatedHost = $e(gethostname());

$summaryChips = $statusCounts->map(fn ($n, $s) => $badge($s).' '.$n)->implode(' &nbsp; ');
$moduleChips = $groups->map(fn ($g, $name) => '<a class="chip" href="#m-'.md5($name).'">'.$e($name).' <b>'.$g->count().'</b></a>')->implode(' ');

$index = '';
foreach ($items as $it) {
    $r = $it['row'];
    $index .= '<tr>'
        .'<td class="nowrap"><a href="#r'.$r->id.'">#'.$r->id.'</a></td>'
        .'<td class="nowrap">'.$e($when($r)).'</td>'
        .'<td class="nowrap">'.$e($it['module']).'</td>'
        .'<td>'.$e($r->summary ?: mb_strimwidth((string) $r->request_user, 0, 160, '…')).'</td>'
        .'<td class="nowrap">'.$badge($r->status).'</td>'
        .'</tr>';
}

$sections = '';
foreach ($groups as $module => $groupItems) {
    $sections .= '<section id="m-'.md5($module).'"><h2>'.$e($module).' <small>'.$groupItems->count().' request(s)</small></h2>';
    foreach ($groupItems as $it) {
        $r = $it['row'];
        $meta = array_filter([
            'Requested '.$when($r),
            $r->location ? 'at '.$r->location : null,
            $r->category ? 'Category: '.$r->category : null,
            $r->company_context ? 'Company: '.$r->company_context : null,
        ]);
        $filesHtml = '';
        foreach ($it['files'] as $fm => $list) {
            $filesHtml .= '<div class="fgroup"><span class="fmod">'.$e($fm).'</span><ul class="files">'
                .implode('', array_map(fn ($f) => '<li><code>'.$e($f).'</code></li>', $list)).'</ul></div>';
        }
        $also = array_values(array_filter(array_keys($it['files']), fn ($m) => $m !== $module && $m !== 'Other'));
        $sections .= '<article class="card" id="r'.$r->id.'">'
            .'<header><span class="rid">#'.$r->id.'</span> '.$badge($r->status)
            .' <span class="title">'.$e($r->summary).'</span></header>'
            .'<div class="meta">'.$e(implode(' · ', $meta)).($also ? ' · Also touches: '.$e(implode(', ', $also)) : '').'</div>'
            .'<div class="block"><div class="label">Request</div><div class="text pre">'.$e($r->request_user).'</div></div>'
            .($r->claude_understand ? '<div class="block"><div class="label">Understood as</div><div class="text">'.$e($r->claude_understand).'</div></div>' : '')
            .'<div class="block solution"><div class="label">Solution</div><div class="text md">'.($r->done ? $md((string) $r->done) : $e($r->changes_detail)).'</div></div>'
            .($r->changes_detail ? '<div class="block"><div class="label">What changed</div><div class="text pre">'.$e($r->changes_detail).'</div></div>' : '')
            .($filesHtml ? '<div class="block"><div class="label">Files affected</div>'.$filesHtml.'</div>' : '')
            .'</article>';
    }
    $sections .= '</section>';
}

$body = $rows->isEmpty()
    ? '<p class="empty">'.($reportDate ? 'No kt_logs rows logged on this day.' : 'No kt_logs rows found yet -- run Protocol Kelsy first.').'</p>'
    : '<div class="chips">'.$moduleChips.'</div>'
        .'<h2>Index</h2><table class="index"><thead><tr><th>#</th><th>Date requested</th><th>Module</th><th>Request</th><th>Status</th></tr></thead><tbody>'.$index.'</tbody></table>'
        .$sections;

$html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>KT-Murano -- {$e($reportTitle)}</title>
<style>
  body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; background: #f4f5f8; color: #1a1a1a; }
  .wrap { max-width: 1040px; margin: 0 auto; padding: 24px 16px 48px; }
  h1 { font-size: 20px; margin: 0 0 4px; }
  h2 { font-size: 16px; margin: 28px 0 10px; padding-bottom: 6px; border-bottom: 2px solid #1a56db; color: #1a56db; }
  h2 small { font-size: 12px; color: #666; font-weight: 400; }
  .meta-top { color: #666; font-size: 12.5px; margin-bottom: 10px; }
  .chips { display: flex; flex-wrap: wrap; gap: 6px; margin: 12px 0 4px; }
  .chip { background: #fff; border: 1px solid #dfe3ea; border-radius: 999px; padding: 3px 10px; font-size: 12px; color: #333; text-decoration: none; }
  .chip b { color: #1a56db; }
  table.index { width: 100%; border-collapse: collapse; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.08); font-size: 12.5px; }
  table.index th, table.index td { border: 1px solid #e5e7eb; padding: 6px 8px; text-align: left; vertical-align: top; }
  table.index th { background: #1a56db; color: #fff; }
  .nowrap { white-space: nowrap; }
  a { color: #1a56db; }
  .card { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.08); padding: 14px 18px; margin-bottom: 14px; }
  .card header { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; font-size: 14.5px; }
  .rid { font-weight: 700; color: #1a56db; }
  .title { font-weight: 600; }
  .meta { color: #666; font-size: 12px; margin: 6px 0 4px; }
  .badge { color: #fff; padding: 2px 10px; border-radius: 12px; font-size: 11.5px; font-weight: 600; white-space: nowrap; }
  .block { padding: 8px 0; border-top: 1px solid #f0f0f0; }
  .label { color: #666; font-weight: 700; font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 4px; }
  .text { font-size: 13px; line-height: 1.5; }
  .pre { white-space: pre-wrap; }
  .solution { background: #f6f9ff; margin: 0 -18px; padding: 8px 18px; }
  .md p { margin: 0 0 6px; }
  .md ul, .md ol { margin: 0 0 6px 20px; padding: 0; }
  .md code, code { background: #eef1f6; padding: 0 4px; border-radius: 3px; font-size: 12px; }
  table.mdt { border-collapse: collapse; margin: 4px 0 8px; font-size: 12.5px; }
  table.mdt th, table.mdt td { border: 1px solid #dde2ea; padding: 4px 8px; text-align: left; }
  .fgroup { margin: 2px 0 6px; }
  .fmod { display: inline-block; font-size: 11.5px; font-weight: 600; color: #1a56db; margin-bottom: 2px; }
  ul.files { margin: 0 0 0 18px; padding: 0; font-size: 12px; }
  .empty { color: #666; }
  @media (max-width: 640px) { table.index td:nth-child(3), table.index th:nth-child(3) { display: none; } }
</style>
</head>
<body>
<div class="wrap">
  <h1>KT-Murano -- {$e($reportTitle)}</h1>
  <div class="meta-top">Generated {$generatedAt} on {$generatedHost} -- {$e($reportScope)} &nbsp; {$summaryChips}</div>
  {$body}
</div>
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
