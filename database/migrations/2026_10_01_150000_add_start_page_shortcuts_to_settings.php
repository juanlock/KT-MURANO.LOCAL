<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Defaults -> Users -> "Shortcut buttons": the 8 square shortcuts on top of
 * the dashboard when the start page is customized by each user. JSON list of
 * 8 slots (SPA path or null); an empty slot shows that user's most used form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'start_page_shortcuts')) {
                $table->text('start_page_shortcuts')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('start_page_shortcuts');
        });
    }
};
