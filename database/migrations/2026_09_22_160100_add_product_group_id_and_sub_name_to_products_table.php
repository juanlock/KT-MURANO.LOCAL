<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Child-side of the product_groups relationship (see
 * 2026_09_22_160000_create_product_groups_table.php for why).
 *
 * `sub_name` matches billing's live column exactly (varchar(250), nullable,
 * no DB default -- 'MAIN' is applied at the app layer, same as billing's
 * ProductsController). `product_group_id` is the new part: a real nullable
 * FK, with a DB-level unique constraint on (company_id, product_group_id,
 * sub_name) replacing billing's app-only (company_id, name, sub_name)
 * check. NULL product_group_id rows (ungrouped products) are exempt from
 * the constraint, same as MySQL's normal NULL-is-distinct unique-index
 * behavior -- that's intentional, only grouped siblings need de-duplication.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('product_group_id')->nullable()->after('id');
            $table->string('sub_name', 250)->nullable()->after('name');

            $table->foreign('product_group_id')->references('id')->on('product_groups');
            $table->unique(['company_id', 'product_group_id', 'sub_name'], 'products_group_subname_unique');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_group_subname_unique');
            $table->dropForeign(['product_group_id']);
            $table->dropColumn(['product_group_id', 'sub_name']);
        });
    }
};
