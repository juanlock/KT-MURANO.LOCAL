<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-language copy for the admin-authored storefront text that lives in
     * store_settings (hero, topbar, footer blurb, SEO meta), as one map:
     * {"footer_text": {"es": "…", "fr": "…"}, "hero_title": {…}}.
     * One column rather than seven, since these are all short free-text fields.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('store_settings', 'text_translations')) {
            Schema::table('store_settings', function (Blueprint $table) {
                $table->json('text_translations')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('store_settings', 'text_translations')) {
            Schema::table('store_settings', function (Blueprint $table) {
                $table->dropColumn('text_translations');
            });
        }
    }
};
