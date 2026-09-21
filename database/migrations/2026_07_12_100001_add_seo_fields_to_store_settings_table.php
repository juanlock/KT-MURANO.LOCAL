<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('store_settings', 'store_domain')) {
                // Canonical base URL for the storefront, e.g. https://shop.example.com
                $table->string('store_domain')->nullable()->after('seo_meta_description');
            }
            if (! Schema::hasColumn('store_settings', 'seo_title_template')) {
                // e.g. "{page} — {store}"
                $table->string('seo_title_template')->nullable()->after('store_domain');
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            foreach (['seo_title_template', 'store_domain'] as $col) {
                if (Schema::hasColumn('store_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
