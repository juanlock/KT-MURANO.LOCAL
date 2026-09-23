<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sub Name is dropped -- it was the app-layer disambiguator for siblings
 * within a Group Name before `product_groups` existed as a real table
 * (see 2026_09_22_160000_create_product_groups_table.php). Now that every
 * product's own Name/Code already identifies it, Sub Name is redundant, and
 * the UI field was removed. The unique constraint on (company_id,
 * product_group_id, sub_name) goes first since it references the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        // products_group_subname_unique (company_id, product_group_id,
        // sub_name) is also the only index covering company_id's own FK
        // (products_company_id_foreign) -- give that FK its own supporting
        // index first, or dropping the unique constraint below fails with
        // "needed in a foreign key constraint".
        Schema::table('products', function (Blueprint $table) {
            $table->index('company_id', 'products_company_id_index');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_group_subname_unique');
            $table->dropColumn('sub_name');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('sub_name', 250)->nullable()->after('name');
            $table->unique(['company_id', 'product_group_id', 'sub_name'], 'products_group_subname_unique');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_company_id_index');
        });
    }
};
