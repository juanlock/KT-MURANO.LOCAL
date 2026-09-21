<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Sold as a classified ad: shown for inquiry only (Request a Quotation),
            // not direct purchase. Service-type products are quote-only too.
            $table->boolean('is_classified')->default(false)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_classified');
        });
    }
};
