<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a quotation to the sale it was converted into, so the list can hide
 * the "Convert to Invoice" action and flag the Ref once a conversion exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('quotations', 'converted_sale_id')) {
            Schema::table('quotations', function (Blueprint $table) {
                $table->unsignedBigInteger('converted_sale_id')->nullable()->after('statut');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('quotations', 'converted_sale_id')) {
            Schema::table('quotations', function (Blueprint $table) {
                $table->dropColumn('converted_sale_id');
            });
        }
    }
};
