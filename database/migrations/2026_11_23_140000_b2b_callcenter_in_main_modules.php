<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * B2B and Call center inside "Main Modules" (user, 2026-11-23: "pq callcenter y b2b no estan dentro de modules"). Both
 * modules came after the saved menus, so with no menu_positions row they sat as loose groups at the end of the menu. Here
 * they get their row inside the company's "Main Modules" group (custom_menul1), at its end -- in AdminSystem (999) and in
 * every company without its own menu (companies.menu_own = 0) that has that group and no row for them yet. The companies
 * with their own menu (Hordex's B2B on top, KcolTech, Alo Mundo's Call center...) are not touched. Safe to run twice.
 */
return new class extends Migration
{
    private const KEYS = ['b2b', 'callcenter'];

    private const GROUP = 'custom_menul1';

    public function up(): void
    {
        $companies = DB::table('companies')->where(fn ($q) => $q->where('menu_own', 0)->orWhereNull('menu_own'))->pluck('id');
        foreach ($companies as $cid) {
            if (! DB::table('menu_positions')->where('company_id', $cid)->where('item_key', self::GROUP)->exists()) {
                continue;
            }
            foreach (self::KEYS as $key) {
                if (DB::table('menu_positions')->where('company_id', $cid)->where('item_key', $key)->exists()) {
                    continue;
                }
                $next = (int) DB::table('menu_positions')->where('company_id', $cid)->where('parent_key', self::GROUP)->max('sort_order') + 1;
                DB::table('menu_positions')->insert([
                    'company_id' => $cid, 'item_key' => $key, 'parent_key' => self::GROUP, 'sort_order' => $next,
                    'external' => 0, 'is_custom' => 0, 'hidden' => 0, 'location' => 'sidebar',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
    }
};
