<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HORDEX EIRL's own menu = its earlier system's sidebar, same names and order (user's rule, 2026-11-21): Escritorio,
 * Clientes, Proveedores, Productos, Compras (goods received), Facturas de Hordex, Facturas de Proveedores, Guías, Kardex,
 * Control de Pagos, Reportes -- top-level items of its menu_positions. Before this, "Compras" pointed to the suppliers'
 * invoices and the two Facturas entries were missing. Found by name, not id; nothing happens where Hordex does not exist.
 * Safe to run twice.
 */
return new class extends Migration
{
    private const ITEMS = [
        ['/app/b2b/dashboard', 'Escritorio'],
        ['/app/b2b/clients', 'Clientes'],
        ['/app/b2b/providers', 'Proveedores'],
        ['/app/b2b/products', 'Productos'],
        ['/app/b2b/receipts', 'Compras'],
        ['/app/b2b/invoices', 'Facturas de Hordex'],
        ['/app/b2b/purchases', 'Facturas de Proveedores'],
        ['/app/b2b/guides', 'Guías'],
        ['/app/b2b/kardex', 'Kardex'],
        ['/app/b2b/collections', 'Control de Pagos'],
        ['/app/b2b/reports', 'Reportes'],
    ];

    public function up(): void
    {
        $cid = DB::table('companies')->where('name', 'like', 'HORDEX%')->value('id');
        if (! $cid) {
            return;
        }
        DB::transaction(function () use ($cid) {
            $keys = array_column(self::ITEMS, 0);
            $n = count(self::ITEMS);
            // The other top-level entries go after these (their order kept).
            $others = DB::table('menu_positions')->where('company_id', $cid)->whereNull('parent_key')->whereNotIn('item_key', $keys)
                ->orderBy('sort_order')->orderBy('id')->pluck('id');
            foreach ($others as $i => $id) {
                DB::table('menu_positions')->where('id', $id)->update(['sort_order' => $n + $i, 'updated_at' => now()]);
            }
            foreach (self::ITEMS as $i => [$key, $label]) {
                DB::table('menu_positions')->where('company_id', $cid)->where('item_key', $key)->whereNotNull('parent_key')->delete();
                DB::table('menu_positions')->updateOrInsert(['company_id' => $cid, 'item_key' => $key, 'parent_key' => null],
                    // icon null: the page's own icon (menuItemIcons.js, the equivalents of the earlier sidebar's).
                    ['label' => $label, 'icon' => null, 'sort_order' => $i, 'hidden' => 0, 'is_custom' => 0, 'location' => 'sidebar', 'external' => 0,
                        'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        // The earlier rows are not restored (they were incomplete); the items stay as they are.
    }
};
