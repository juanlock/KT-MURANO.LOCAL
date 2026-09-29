<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS Settings -> "Show POS button in header" (per company, default on): the blue
 * POS button in the admin top header. Off hides it; the POS stays reachable from
 * the sidebar and the account menu.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pos_settings', 'show_pos_button')) {
            Schema::table('pos_settings', function (Blueprint $table) {
                $table->boolean('show_pos_button')->default(1);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pos_settings', 'show_pos_button')) {
            Schema::table('pos_settings', function (Blueprint $table) {
                $table->dropColumn('show_pos_button');
            });
        }
    }
};
