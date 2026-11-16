<?php

use App\Models\Setting;
use App\Support\CompanyFiles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Murano's own logo for AdminSystem (company 999, the SuperAdmin; user, 2026-11-16):
 * the light square version from KT-TOOLS/murano, background removed. A new company
 * still starts with the neutral default (Setting::forCompany). Safe to run twice.
 */
return new class extends Migration
{
    private const FILE = 'murano-cuadrado.png';

    public function up(): void
    {
        $cid = Setting::TEMPLATE_COMPANY_ID;
        $current = DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->value('logo');
        if ($current === null || str_contains((string) $current, 'murano-cuadrado')) {
            return;
        }
        $file = database_path('assets/murano/'.self::FILE);
        if (! is_file($file)) {
            return;
        }
        $path = CompanyFiles::putBytes($cid, 'logos', self::FILE, file_get_contents($file));
        DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->update(['logo' => $path]);
    }

    public function down(): void
    {
        if (is_file(public_path(Setting::DEFAULT_LOGO))) {
            DB::table('settings')->where('company_id', Setting::TEMPLATE_COMPANY_ID)->update(['logo' => Setting::DEFAULT_LOGO]);
        }
    }
};
