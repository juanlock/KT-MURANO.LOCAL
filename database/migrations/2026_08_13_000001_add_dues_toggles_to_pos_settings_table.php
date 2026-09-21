<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-receipt toggles for the "Previous Dues" and "Net Balance" lines, which
 * every POS receipt layout prints when the customer carries an outstanding
 * balance. Default 1 keeps the current behaviour for existing installs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_settings', 'show_previous_dues')) {
                $table->boolean('show_previous_dues')->default(1)->after('show_due');
            }
            if (!Schema::hasColumn('pos_settings', 'show_net_balance')) {
                $table->boolean('show_net_balance')->default(1)->after('show_previous_dues');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_settings', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['show_previous_dues', 'show_net_balance'],
                fn ($c) => Schema::hasColumn('pos_settings', $c)
            ));

            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }
};
