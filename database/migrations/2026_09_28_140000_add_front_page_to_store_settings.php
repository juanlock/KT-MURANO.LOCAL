<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Store Management -> Store Settings -> Front page, per company:
 *  - 'sections': the store's own front page, built from editable sections
 *    (homepage_lineup: hero, collections, banners, flash sale, ...)
 *  - 'imported': a page imported from a URL, kept in its original format
 *    (files under public/files/company_{id}/store/sites/...), with the
 *    Murano store bar inserted (App\Services\FrontPage).
 *
 * Backfill: companies whose Home page already holds an imported site
 * (KcolTech, Ruba Garden, NewEra) start in 'imported' mode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('store_settings', 'front_page_mode')) {
                $table->string('front_page_mode', 20)->default('sections');
            }
            if (! Schema::hasColumn('store_settings', 'front_page_url')) {
                $table->string('front_page_url', 255)->nullable();
            }
            if (! Schema::hasColumn('store_settings', 'front_page_path')) {
                $table->string('front_page_path', 255)->nullable();
            }
            if (! Schema::hasColumn('store_settings', 'front_page_imported_at')) {
                $table->timestamp('front_page_imported_at')->nullable();
            }
        });

        $urls = [1 => null, 2 => 'https://www.rubagardenlandscape.com', 3 => 'http://newera.local/'];
        $homes = DB::table('store_pages')->where('slug', 'home')->where('use_site_design', 1)->whereNotNull('site_path')->get(['company_id', 'site_path', 'updated_at']);
        foreach ($homes as $h) {
            DB::table('store_settings')->where('company_id', $h->company_id)->update([
                'front_page_mode' => 'imported',
                'front_page_path' => $h->site_path,
                'front_page_url' => $urls[$h->company_id] ?? null,
                'front_page_imported_at' => $h->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            foreach (['front_page_mode', 'front_page_url', 'front_page_path', 'front_page_imported_at'] as $c) {
                if (Schema::hasColumn('store_settings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
