<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Turismo Cautivo's side menu in six groups (user's request, 2026-11-04), that
 * company only: Pasajeros, Encomiendas, Flota y Logística, Back Office, Módulos
 * adicionales and Configuración, with the Panel on top. The company is live, so
 * this goes as a migration. Only places and names change: no permission, no
 * other company. Runs once: when the first group exists the company's menu is
 * its own again (Manage Menu) and this does nothing. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if (! $cid || DB::table('menu_positions')->where('company_id', $cid)->where('item_key', 'custom_tc_pasajeros')->exists()) {
            return;
        }

        DB::transaction(function () use ($cid) {
            $now = now();
            $rows = fn () => DB::table('menu_positions')->where('company_id', $cid);
            $place = function (string $key, ?string $parent, int $sort, array $extra = []) use ($cid, $now, $rows) {
                $set = ['parent_key' => $parent, 'sort_order' => $sort, 'updated_at' => $now] + $extra;
                if ($rows()->where('item_key', $key)->exists()) {
                    $rows()->where('item_key', $key)->update($set);
                } else {
                    DB::table('menu_positions')->insert($set + ['company_id' => $cid, 'item_key' => $key, 'external' => 0, 'is_custom' => 0, 'hidden' => 0, 'location' => 'sidebar', 'created_at' => $now]);
                }
            };
            $group = fn (string $key, string $label, string $icon, int $sort) => $place($key, null, $sort, ['label' => $label, 'icon' => $icon, 'is_custom' => 1, 'hidden' => 0, 'location' => 'sidebar']);

            $place('dashboard', null, 0);
            $group('custom_tc_pasajeros', 'Módulo de Pasajeros', 'users', 1);
            $group('custom_tc_encomiendas', 'Módulo de Encomiendas', 'archive', 2);
            $group('custom_tc_flota', 'Flota y Logística (TMS)', 'bus', 3);
            $group('custom_tc_backoffice', 'Back Office y Administración', 'library-big', 4);
            // These two already exist: the additional modules' group and the settings.
            $place('custom_menul1', null, 5, ['label' => 'Módulos adicionales']);
            $place('settings', null, 6, ['label' => 'Configuración']);

            $fill = function (string $parent, array $keys) use ($place) {
                foreach ($keys as $i => $key) {
                    $place($key, $parent, $i);
                }
            };
            // 1. Selling and scheduling: the POS, routes, passengers, the fares (ticket products).
            $fill('custom_tc_pasajeros', ['custom_pos', '/app/transport/routes', 'People', 'products']);
            // 2. Parcels: only the sales' shipments exist so far; the module itself is still to be built.
            $fill('custom_tc_encomiendas', ['/app/sales/shipment']);
            // 3. Dispatch (a trip = bus + crew) and the fleet's own pages.
            $fill('custom_tc_flota', ['/app/transport/trips', '/app/fleet/dashboard', '/app/fleet/vehicles', '/app/fleet/maintenance', '/app/fleet/fuel-logs', '/app/fleet/assignments', '/app/fleet/reports']);
            // 4. Finance, people and reports.
            $fill('custom_tc_backoffice', ['sales', 'accounting', '/app/transport/staff', 'custom_company', 'custom_suppliers', 'purchases', 'reports']);

            // The two old containers are empty now: hidden, or they would come back as empty groups.
            foreach (['transport', 'fleet'] as $key) {
                $rows()->where('item_key', $key)->update(['hidden' => 1, 'updated_at' => $now]);
            }

            // 5. What is left of the top level goes first among the additional modules.
            $rows()->where('parent_key', 'custom_menul1')->increment('sort_order', 10);
            $fill('custom_menul1', ['forms', 'website', 'portfolio', 'assets', 'service']);

            // The cash registers report belongs with the POS (its old group, Accounting, is off here).
            $place('/app/reports/cash-registers', 'custom_pos', 5);

            // 6. Every settings page of a module, after the ones Configuración already has.
            $next = (int) $rows()->where('parent_key', 'settings')->max('sort_order') + 1;
            foreach (['/app/settings/pos_settings', '/app/customer-display/setup', '/app/settings/Warehouses', '/app/email-office/settings', '/app/portfolio/settings',
                '/app/helpdesk/settings', '/app/marketing/settings', '/app/EWallet/Settings', '/app/Store/Settings', '/app/Store/PaymentGateway', 'group:Service_Setup', '/app/hospital/setup'] as $key) {
                $place($key, 'settings', $next++);
            }
            // Each one is called "Settings" in its own module: here they sit side by side.
            foreach (['/app/email-office/settings' => 'Configuración de correo', '/app/portfolio/settings' => 'Configuración de portafolio', '/app/helpdesk/settings' => 'Configuración de Helpdesk',
                '/app/marketing/settings' => 'Configuración de marketing', '/app/EWallet/Settings' => 'Configuración de E-Wallet', '/app/Store/Settings' => 'Configuración de tienda'] as $key => $label) {
                $rows()->where('item_key', $key)->update(['label' => $label, 'updated_at' => $now]);
            }
        });
    }

    public function down(): void
    {
        // Manage Menu -> Reset puts the company's menu back.
    }
};
