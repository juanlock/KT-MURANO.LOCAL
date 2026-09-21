<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-language CMS page copy. The slug is deliberately NOT translated: one
     * page keeps one URL so links, canonicals and the sitemap stay stable.
     */
    public function up(): void
    {
        Schema::table('store_pages', function (Blueprint $table) {
            foreach (['title', 'content', 'seo_title', 'seo_description'] as $field) {
                if (! Schema::hasColumn('store_pages', $field.'_translations')) {
                    $table->json($field.'_translations')->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('store_pages', function (Blueprint $table) {
            foreach (['title', 'content', 'seo_title', 'seo_description'] as $field) {
                if (Schema::hasColumn('store_pages', $field.'_translations')) {
                    $table->dropColumn($field.'_translations');
                }
            }
        });
    }
};
