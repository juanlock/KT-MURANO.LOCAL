<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global switch for "Change Salesperson During Checkout": when enabled, the
 * POS shows a salesperson picker and the chosen user is saved as the sale's
 * user_id (seller) without switching the logged-in session. Defaults to false
 * so existing installs are unaffected until an admin opts in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'enable_pos_salesperson_switch')) {
                $table->boolean('enable_pos_salesperson_switch')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'enable_pos_salesperson_switch')) {
                $table->dropColumn('enable_pos_salesperson_switch');
            }
        });
    }
};
