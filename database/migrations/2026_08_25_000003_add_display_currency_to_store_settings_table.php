<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The storefront's default DISPLAY currency — distinct from
     * settings.currency_id, which stays the accounting base every amount is
     * stored in. NULL means "show the base currency". The matching default
     * language reuses the existing (previously unused) `language` column.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('store_settings', 'display_currency_id')) {
            Schema::table('store_settings', function (Blueprint $table) {
                $table->integer('display_currency_id')->nullable()->after('currency_code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('store_settings', 'display_currency_id')) {
            Schema::table('store_settings', function (Blueprint $table) {
                $table->dropColumn('display_currency_id');
            });
        }
    }
};
