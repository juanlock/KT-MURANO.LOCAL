<?php

// Protocol Preparate -- prints the FULL kt_logs history (every request/order
// made to modify this project) as one compact line per row, oldest first, so
// a session knows what's already been built/changed/decided before touching
// anything. Long-text columns (request_user, changes_detail, files_modified)
// are left out on purpose -- pull those per row when a task needs them.
// Run via (absolute path -- a relative require fails inside tinker):
// php artisan tinker --execute="require '<absolute-path-to-repo>\database\scripts\protocol_preparate_history.php';"

use App\Models\KtLog;

$rows = KtLog::orderBy('id')->get();

foreach ($rows as $r) {
    echo sprintf(
        "#%d %s [%s] [%s] [%s] [%s] %s\n",
        $r->id,
        $r->log_date?->format('Y-m-d'),
        $r->location,
        $r->status ?? '-',
        $r->category ?? '-',
        $r->company_context ?? '-',
        $r->summary
    );
}

echo sprintf(
    "\n%d rows, %s .. %s\n",
    $rows->count(),
    $rows->first()?->log_date?->format('Y-m-d') ?? '-',
    $rows->last()?->log_date?->format('Y-m-d') ?? '-'
);
