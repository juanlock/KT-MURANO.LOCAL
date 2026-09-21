<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the `show_customer_history` toggle to pos_settings: shows the
     * "Purchase history" button next to the selected customer in the POS.
     * Defaults ON so existing installs get the feature after upgrade.
     */
    public function up(): void
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_settings', 'show_customer_history')) {
                $table->boolean('show_customer_history')->default(1);
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            if (Schema::hasColumn('pos_settings', 'show_customer_history')) {
                $table->dropColumn('show_customer_history');
            }
        });
    }
};
