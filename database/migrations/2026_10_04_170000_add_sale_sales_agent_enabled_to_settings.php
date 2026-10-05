<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System Settings -> Defaults -> Default - Sales: show/hide the Sales Agent
 * field on the Sales Create/Edit form (like Shipping), per company. Default
 * on -- the field still also needs the Commissions module.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'sale_sales_agent_enabled')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->boolean('sale_sales_agent_enabled')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'sale_sales_agent_enabled')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn('sale_sales_agent_enabled');
            });
        }
    }
};
