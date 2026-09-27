<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'sale_loyalty_points_enabled')) {
                $table->boolean('sale_loyalty_points_enabled')->default(true);
            }
            if (! Schema::hasColumn('settings', 'sale_shipping_enabled')) {
                $table->boolean('sale_shipping_enabled')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'sale_loyalty_points_enabled')) {
                $table->dropColumn('sale_loyalty_points_enabled');
            }
            if (Schema::hasColumn('settings', 'sale_shipping_enabled')) {
                $table->dropColumn('sale_shipping_enabled');
            }
        });
    }
};
