<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'sale_show_price_type_dropdown')) {
                $table->boolean('sale_show_price_type_dropdown')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'sale_show_price_type_dropdown')) {
                $table->dropColumn('sale_show_price_type_dropdown');
            }
        });
    }
};
