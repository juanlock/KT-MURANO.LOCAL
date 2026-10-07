<?php

use App\Support\WebsitePaths;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Website Management -> Website URL (2026-10-06): each company website has its
 * own address http://host/{url_path} instead of /online_store?company=ID.
 * Every company starts with its name as path ("KcolTech" -> /kcoltech).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_settings', function (Blueprint $t) {
            if (! Schema::hasColumn('website_settings', 'url_path')) {
                $t->string('url_path', 60)->nullable()->unique()->after('site_name');
            }
        });
        foreach (DB::table('website_settings')->whereNull('url_path')->get(['id', 'company_id']) as $w) {
            if ((int) $w->company_id === 999) {
                continue; // the SuperAdmin template company has no website of its own
            }
            $name = DB::table('companies')->where('id', $w->company_id)->value('name');
            DB::table('website_settings')->where('id', $w->id)->update(['url_path' => WebsitePaths::suggest((int) $w->company_id, $name)]);
        }
        WebsitePaths::forget();
    }

    public function down(): void
    {
        Schema::table('website_settings', function (Blueprint $t) {
            if (Schema::hasColumn('website_settings', 'url_path')) {
                $t->dropUnique(['url_path']);
                $t->dropColumn('url_path');
            }
        });
        WebsitePaths::forget();
    }
};
