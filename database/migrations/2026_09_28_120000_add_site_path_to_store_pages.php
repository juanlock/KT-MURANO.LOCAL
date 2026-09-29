<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store -> Pages: a page can show an imported website in its ORIGINAL design
 * (its own HTML/CSS/JS, published under public/files/company_{id}/store/
 * sites/...). site_path is that site's entry file relative to public/, e.g.
 * files/company_3/store/sites/newera/index.html. When set (and
 * use_site_design is on) the storefront shows it full-screen instead of the
 * page's HTML content, which is kept as the editable fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_pages', function (Blueprint $table) {
            if (! Schema::hasColumn('store_pages', 'site_path')) {
                $table->string('site_path', 255)->nullable()->after('content');
            }
            if (! Schema::hasColumn('store_pages', 'use_site_design')) {
                $table->boolean('use_site_design')->default(false)->after('site_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_pages', function (Blueprint $table) {
            foreach (['site_path', 'use_site_design'] as $c) {
                if (Schema::hasColumn('store_pages', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
