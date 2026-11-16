<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Creating a quotation works like creating an invoice (user's request, 2026-11-16): a quotation line keeps its
 * Description (note) and its pack like a sale line, and the quotation keeps the order tax / discount method, the
 * sales agent and the seller like a sale -- all of it carried to the invoice when the quotation is converted.
 * Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_details', function (Blueprint $t) {
            if (! Schema::hasColumn('quotation_details', 'note')) {
                $t->text('note')->nullable()->after('imei_number');
            }
            if (! Schema::hasColumn('quotation_details', 'product_pack_id')) {
                $t->unsignedBigInteger('product_pack_id')->nullable()->after('sale_unit_id');
            }
            if (! Schema::hasColumn('quotation_details', 'pack_multiplier')) {
                $t->decimal('pack_multiplier', 15, 4)->default(1)->after('product_pack_id');
            }
            if (! Schema::hasColumn('quotation_details', 'pack_name')) {
                $t->string('pack_name', 191)->nullable()->after('pack_multiplier');
            }
        });
        Schema::table('quotations', function (Blueprint $t) {
            if (! Schema::hasColumn('quotations', 'tax_method')) {
                $t->string('tax_method', 10)->default('1')->after('tax_rate'); // 1 = %, 2 = fixed amount
            }
            if (! Schema::hasColumn('quotations', 'discount_Method')) {
                $t->string('discount_Method', 10)->default('2')->after('discount'); // 1 = %, 2 = fixed amount
            }
            if (! Schema::hasColumn('quotations', 'sales_agent_id')) {
                $t->unsignedBigInteger('sales_agent_id')->nullable()->after('client_id');
            }
            if (! Schema::hasColumn('quotations', 'seller_id')) {
                $t->unsignedBigInteger('seller_id')->nullable()->after('user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('quotation_details', function (Blueprint $t) {
            foreach (['note', 'product_pack_id', 'pack_multiplier', 'pack_name'] as $c) {
                if (Schema::hasColumn('quotation_details', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
        Schema::table('quotations', function (Blueprint $t) {
            foreach (['tax_method', 'discount_Method', 'sales_agent_id', 'seller_id'] as $c) {
                if (Schema::hasColumn('quotations', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
