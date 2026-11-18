<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * KcolTech's products could not be picked on a quotation or sale (user, 2026-11-18: "¿por qué KcolTech no tiene productos?"):
 * 41 of its 45 products (the services PPI-001..041 its invoices use, brought from the earlier system) had no product_warehouse
 * row, and those forms list only the products of the chosen warehouse (ProductsController::Products_by_Warehouse). Each active
 * product of KcolTech without a row gets one in every KcolTech warehouse (stock 0; services don't manage stock). Safe to run
 * twice; nothing else is changed. Other companies with the same gap are left for the user to decide.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cid = DB::table('companies')->where('name', 'KcolTech')->value('id');
        if (! $cid) {
            return;
        }
        $warehouses = DB::table('warehouses')->where('company_id', $cid)->whereNull('deleted_at')->pluck('id');
        if ($warehouses->isEmpty()) {
            return;
        }
        $products = DB::table('products as p')->where('p.company_id', $cid)->whereNull('p.deleted_at')->where('p.is_active', 1)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('product_warehouse as pw')->whereColumn('pw.product_id', 'p.id')->whereNull('pw.deleted_at'))
            ->get(['p.id', 'p.type']);
        $rows = [];
        foreach ($products as $p) {
            foreach ($warehouses as $w) {
                $rows[] = ['product_id' => $p->id, 'warehouse_id' => $w, 'product_variant_id' => null, 'qte' => 0,
                    'manage_stock' => $p->type === 'is_service' ? 0 : 1, 'created_at' => now(), 'updated_at' => now()];
            }
        }
        DB::transaction(function () use ($rows) {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('product_warehouse')->insert($chunk);
            }
        });
    }

    public function down(): void
    {
        // Left in place: removing the rows would hide the products from quotations and sales again.
    }
};
