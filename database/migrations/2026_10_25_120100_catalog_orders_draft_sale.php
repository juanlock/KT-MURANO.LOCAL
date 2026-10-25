<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Portfolio tablet orders: the POS held order (draft_sales) each one created. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('portfolio_catalog_orders', 'draft_sale_id')) {
            Schema::table('portfolio_catalog_orders', function (Blueprint $t) {
                $t->unsignedBigInteger('draft_sale_id')->nullable()->after('total')->index();
            });
        }
    }

    public function down(): void
    {
        Schema::table('portfolio_catalog_orders', fn (Blueprint $t) => $t->dropColumn('draft_sale_id'));
    }
};
