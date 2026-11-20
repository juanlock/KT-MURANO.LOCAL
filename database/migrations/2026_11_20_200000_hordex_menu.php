<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * HORDEX EIRL's menu (user's rules, 2026-11-20): the same options and order as its earlier system's side bar -- Inicio
 * (renamed from "Tablero B2B"), Clientes, Proveedores, Productos, Compras, Facturas, Guías, Kardex, Control de pagos,
 * Reportes -- each a top-level item with its icon; the rest of Murano's menu after them. The Manager sees ONLY those
 * (menu_hide:* on every other top-level item); the IT Admin sees everything. By name; nothing without the company. Safe to run twice.
 */
return new class extends Migration
{
    private const ITEMS = [
        ['/app/b2b/dashboard', 'Inicio', 'layout-grid'],
        ['/app/b2b/clients', 'Clientes', 'users'],
        ['/app/b2b/providers', 'Proveedores', 'library'],
        ['/app/b2b/products', 'Productos', 'archive'],
        ['/app/b2b/purchases', 'Compras', 'shopping-cart'],
        ['/app/b2b/invoices', 'Facturas', 'receipt'],
        ['/app/b2b/guides', 'Guías', 'truck'],
        ['/app/b2b/kardex', 'Kardex', 'clipboard-list'],
        ['/app/b2b/collections', 'Control de pagos', 'wallet'],
        ['/app/b2b/reports', 'Reportes', 'bar-chart'],
    ];

    public function up(): void
    {
        $cid = DB::table('companies')->where('name', 'HORDEX EIRL')->whereNull('deleted_at')->value('id');
        if (! $cid) {
            return;
        }
        $keys = array_column(self::ITEMS, 0);
        // Murano's own purchases list back in its group (2026-11-20: the IT Admin keeps it; the Manager uses Hordex's Compras).
        // Same for Murano's customers, suppliers and products lists (the Manager uses Hordex's Clientes / Proveedores / Productos):
        // back under the group the template company 999 has them in.
        foreach (['/app/purchases/list', '/app/People/Customers', '/app/People/Suppliers', '/app/products/list', '/app/sales/list'] as $key) {
            $parent = DB::table('menu_positions')->where('company_id', 999)->where('item_key', $key)->value('parent_key')
                ?? ['/app/purchases/list' => 'purchases', '/app/People/Customers' => 'People', '/app/People/Suppliers' => 'custom_suppliers',
                    '/app/products/list' => 'products', '/app/sales/list' => 'sales'][$key];
            DB::table('menu_positions')->where('company_id', $cid)->where('item_key', $key)->whereNull('parent_key')
                ->update(['parent_key' => $parent, 'label' => null, 'icon' => null, 'sort_order' => 0, 'updated_at' => now()]);
        }
        foreach (self::ITEMS as $i => [$key, $label, $icon]) {
            $values = ['parent_key' => null, 'label' => $label, 'icon' => $icon, 'sort_order' => $i, 'hidden' => 0, 'location' => 'sidebar', 'updated_at' => now()];
            if (DB::table('menu_positions')->where('company_id', $cid)->where('item_key', $key)->exists()) {
                DB::table('menu_positions')->where('company_id', $cid)->where('item_key', $key)->update($values);
            } else {
                DB::table('menu_positions')->insert($values + ['company_id' => $cid, 'item_key' => $key, 'external' => 0, 'is_custom' => 0, 'created_at' => now()]);
            }
        }
        // Murano's other top-level items after them, in their order.
        $rest = DB::table('menu_positions')->where('company_id', $cid)->whereNull('parent_key')->whereNotIn('item_key', $keys)
            ->orderBy('sort_order')->orderBy('id')->pluck('item_key');
        foreach ($rest->values() as $i => $key) {
            DB::table('menu_positions')->where('company_id', $cid)->whereNull('parent_key')->where('item_key', $key)
                ->update(['sort_order' => count($keys) + $i, 'updated_at' => now()]);
        }
        // The older top-level order agrees.
        DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->update([
            'sidebar_menu_order' => json_encode(array_map(fn ($k) => ['id' => $k], array_merge($keys, $rest->all()))), 'updated_at' => now(),
        ]);

        // The Manager: only the earlier system's options.
        $manager = DB::table('roles')->where('company_id', $cid)->where('name', 'Manager')->whereNull('deleted_at')->value('id');
        if ($manager) {
            // menu_hide works on a screen's route (menuVisibility.js): every route of the company's menu but the ten above
            // (a group with none left visible disappears by itself), plus the general dashboard.
            $routes = DB::table('menu_positions')->where('company_id', $cid)->where('item_key', 'like', '/%')->whereNotIn('item_key', $keys)->pluck('item_key')
                ->merge(['/app/dashboard'])->unique();
            foreach ($routes as $key) {
                $pid = DB::table('permissions')->where('name', 'menu_hide:'.$key)->value('id')
                    ?: DB::table('permissions')->insertGetId(['name' => 'menu_hide:'.$key, 'created_at' => now(), 'updated_at' => now()]);
                if (! DB::table('permission_role')->where('role_id', $manager)->where('permission_id', $pid)->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $manager, 'permission_id' => $pid]);
                }
            }
        }
    }

    public function down(): void
    {
        // Menu order is the company's own data; left as is.
    }
};
