<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kitchen tickets for POS held orders (2026-10-25): an order sent from a
 * Portfolio tablet menu waits in the POS as a draft (draft_sales) AND goes to the
 * Kitchen. A ticket used to need a sale; now it may point to the draft
 * (draft_sale_id, sale_id null) and is moved to the sale when the cashier charges
 * that draft in the POS (PosController). Source 'tablet'.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('kitchen_orders', 'draft_sale_id')) {
            DB::statement('ALTER TABLE kitchen_orders ADD draft_sale_id BIGINT UNSIGNED NULL AFTER sale_id, ADD INDEX kitchen_orders_draft_sale_id_index (draft_sale_id)');
        }
        DB::statement('ALTER TABLE kitchen_orders MODIFY sale_id INT NULL');
    }

    public function down(): void
    {
        // kept (tickets on drafts may exist)
    }
};
