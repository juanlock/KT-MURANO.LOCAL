<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System Settings -> Appearance: "Show breadcrumbs" (the small "Employee /
 * Employee Details" trail above every page title), per company. On by
 * default; off hides it on every page (components/PageHeader.vue).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'show_breadcrumbs')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->boolean('show_breadcrumbs')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'show_breadcrumbs')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn('show_breadcrumbs');
            });
        }
    }
};
