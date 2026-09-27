<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_details', function (Blueprint $table) {
            if (! Schema::hasColumn('quotation_details', 'price_type')) {
                $table->string('price_type', 32)->default('retail')->after('discount_method');
            }
        });
    }

    public function down(): void
    {
        Schema::table('quotation_details', function (Blueprint $table) {
            if (Schema::hasColumn('quotation_details', 'price_type')) {
                $table->dropColumn('price_type');
            }
        });
    }
};
