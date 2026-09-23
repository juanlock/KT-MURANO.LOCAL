<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * settings.default_tax was decimal(10,2) -- only 2 decimal places, so a
 * real-world rate like 6.625% got silently rounded to 6.63% on save. Widen
 * it to decimal(15,3) to match the precision already used for tax rates
 * elsewhere in this schema (products.TaxNet, also decimal(15,3)).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->decimal('default_tax', 15, 3)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->decimal('default_tax', 10, 2)->default(0)->change();
        });
    }
};
