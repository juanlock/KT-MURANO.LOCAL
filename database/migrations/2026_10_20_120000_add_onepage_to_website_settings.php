<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Website Management -> One-page template (2026-10-20): the company's one-page
 * website (template, colour, sections with their texts EN / ES, menu links) as
 * JSON on its website_settings row. Used when store_settings.front_page_mode =
 * 'onepage'. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('website_settings') && ! Schema::hasColumn('website_settings', 'onepage')) {
            Schema::table('website_settings', function (Blueprint $table) {
                $table->longText('onepage')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('website_settings', 'onepage')) {
            Schema::table('website_settings', function (Blueprint $table) {
                $table->dropColumn('onepage');
            });
        }
    }
};
