<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Turismo Cautivo's "Módulo de Pasajeros" shows only what is used (user's rule, 2026-11-24: "que se muestre lo que se
 * tiene, no lo que no se usa.. como portal de cliente.. ponlo en menos menús.. oculta los form que no se usa"). Tickets
 * and parcels are sold in AuraSoft: Turismo has 0 sales, 0 tickets and 3 customers in Murano, so the POS, Customers (with
 * the customer portal), Products, Boarding and "Mi parada" are hidden from the group; it keeps Base de pasajeros and
 * Rutas. Hidden, not deleted: Manage Menu shows them again. Safe to run twice.
 */
return new class extends Migration
{
    private const HIDE = ['custom_pos', 'People', 'products', '/app/transport/boarding', '/app/transport/stop'];

    private const KEEP = ['/app/transport/passengers' => 1, '/app/transport/routes' => 2];

    public function up(): void
    {
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if (! $cid) {
            return;
        }
        $group = DB::table('menu_positions')->where('company_id', $cid)->where('parent_key', 'custom_tc_pasajeros');
        (clone $group)->whereIn('item_key', self::HIDE)->update(['hidden' => 1, 'updated_at' => now()]);
        foreach (self::KEEP as $key => $sort) {
            (clone $group)->where('item_key', $key)->update(['hidden' => 0, 'sort_order' => $sort, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        DB::table('menu_positions')->where('company_id', $cid)->where('parent_key', 'custom_tc_pasajeros')->whereIn('item_key', self::HIDE)->update(['hidden' => 0]);
    }
};
