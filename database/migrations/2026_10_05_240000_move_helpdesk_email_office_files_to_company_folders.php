<?php

use App\Support\CompanyFiles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Project standard (CLAUDE.md -> File uploads): module files live in
 * public/files/company_{id}/{module}/. Helpdesk and Email Office stored theirs
 * in storage/app (helpdesk/company_{id}/..., email-office/{id}/...): move each
 * file and rewrite its path in helpdesk_messages.attachments /
 * office_emails.attachments. Both module folders are private (.htaccess:
 * no direct URL; downloads go through the app). Idempotent: paths already
 * under files/ are left as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->move('helpdesk_messages', 'helpdesk', fn (string $p) => preg_match('#^helpdesk/company_(\d+)/(.+)$#', $p, $m) ? [(int) $m[1], $m[2]] : null);
        $this->move('office_emails', 'email-office', fn (string $p) => preg_match('#^email-office/(\d+)/(.+)$#', $p, $m) ? [(int) $m[1], $m[2]] : null);
    }

    /** $parse(old path) -> [company id, rest] for old-style paths, else null. */
    private function move(string $table, string $module, callable $parse): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'attachments')) {
            return;
        }
        DB::table($table)->whereNotNull('attachments')->where('attachments', 'like', '%"path"%')->orderBy('id')
            ->chunkById(200, function ($rows) use ($table, $module, $parse) {
                foreach ($rows as $row) {
                    $list = json_decode((string) $row->attachments, true) ?: [];
                    $changed = false;
                    foreach ($list as &$a) {
                        $old = (string) ($a['path'] ?? '');
                        $p = $old !== '' ? $parse($old) : null;
                        if (! $p) {
                            continue;
                        }
                        [$cid, $rest] = $p;
                        $new = CompanyFiles::dir($cid, $module, true).'/'.$rest;
                        $src = storage_path('app/'.$old);
                        if (is_file($src)) {
                            if (! is_dir(dirname(public_path($new)))) {
                                mkdir(dirname(public_path($new)), 0755, true);
                            }
                            rename($src, public_path($new));
                        }
                        if (is_file(public_path($new))) {
                            $a['path'] = $new;
                            $changed = true;
                        }
                    }
                    unset($a);
                    if ($changed) {
                        DB::table($table)->where('id', $row->id)->update(['attachments' => json_encode($list)]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Files stay in the company folders (the code only reads them there now).
    }
};
