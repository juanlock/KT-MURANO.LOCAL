<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Portfolio -> project "Website link" (2026-10-20): a "Visit website" button on
 * the project page (website design work, e.g. KcolTech's ready-made websites).
 * Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('portfolio_projects') && ! Schema::hasColumn('portfolio_projects', 'website_url')) {
            Schema::table('portfolio_projects', function (Blueprint $table) {
                $table->string('website_url', 255)->nullable()->after('video_url');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('portfolio_projects', 'website_url')) {
            Schema::table('portfolio_projects', function (Blueprint $table) {
                $table->dropColumn('website_url');
            });
        }
    }
};
