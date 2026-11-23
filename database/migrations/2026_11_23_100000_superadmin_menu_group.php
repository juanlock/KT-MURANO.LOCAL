<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SuperAdmin menu group (user, 2026-11-23: "crea un menu llamado SuperAdmin y mueve ahi todos los formularios que solo
 * tiene acceso superadmin"). config/menu.js has the new top-level group `superadmin`; a company whose menu is saved
 * (Manage Menu, menu_positions) still places those screens under Settings, so here, for EVERY company with a saved menu:
 * the group row right after Settings, and the twelve SuperAdmin-only screens moved into it (shown, in a fixed order).
 * Nobody else ever sees them (superAdminOnly / systemOnly gates in the menu, the router and the server). Safe to run twice.
 */
return new class extends Migration
{
    private const ITEMS = [
        '/app/settings/business_modules',
        '/app/settings/audit_log',
        '/app/settings/zatca_settings',
        '/app/settings/kt_log',
        '/app/settings/roles_matrix',
        '/app/settings/company_manuals',
        '/app/settings/login_page',
        '/app/settings/login_devices',
        '/app/settings/Currencies',
        '/app/settings/Backup',
        '/app/settings/system_health',
        '/app/settings/update_settings',
    ];

    public function up(): void
    {
        $companies = DB::table('menu_positions')->distinct()->pluck('company_id');
        foreach ($companies as $cid) {
            DB::transaction(function () use ($cid) {
                $rows = fn () => DB::table('menu_positions')->where('company_id', $cid);
                if (! $rows()->where('item_key', 'superadmin')->exists()) {
                    $settings = $rows()->where('item_key', 'settings')->whereNull('parent_key')->first();
                    $location = $settings->location ?? 'sidebar';
                    $order = $settings
                        ? $settings->sort_order + 1
                        : (int) $rows()->whereNull('parent_key')->where('location', $location)->max('sort_order') + 1;
                    $rows()->whereNull('parent_key')->where('location', $location)->where('sort_order', '>=', $order)->increment('sort_order');
                    DB::table('menu_positions')->insert([
                        'company_id' => $cid, 'item_key' => 'superadmin', 'parent_key' => null, 'label' => null, 'icon' => null,
                        'route' => null, 'external' => 0, 'is_custom' => 0, 'hidden' => 0, 'location' => $location,
                        'sort_order' => $order, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                foreach (self::ITEMS as $i => $key) {
                    $rows()->where('item_key', $key)
                        ->update(['parent_key' => 'superadmin', 'sort_order' => $i, 'hidden' => 0, 'updated_at' => now()]);
                }
            });
        }
    }

    public function down(): void
    {
        DB::table('menu_positions')->whereIn('item_key', self::ITEMS)->where('parent_key', 'superadmin')
            ->update(['parent_key' => 'settings', 'updated_at' => now()]);
        DB::table('menu_positions')->where('item_key', 'superadmin')->delete();
    }
};
