<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Turismo Cautivo (user, 2026-11-22: "en su menú ponle Formularios arriba, debajo de Tablero principal"): the Forms
 * group leaves Back Office y Administración and goes to the top of the side menu, right after the main dashboard. The
 * menu is the company's (Manage Menu), so every role of Turismo that may see Forms (Operaciones, Director, Manager, IT
 * Admin) sees it there; the others still don't (permissions). By company name, not id. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cid = DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->value('id');
        if (! $cid || ! DB::table('menu_positions')->where('company_id', $cid)->where('item_key', 'forms')->exists()) {
            return;
        }
        if (DB::table('menu_positions')->where('company_id', $cid)->where('item_key', 'forms')->whereNull('parent_key')->where('sort_order', 1)->exists()) {
            return; // already there
        }
        DB::transaction(function () use ($cid) {
            $top = fn () => DB::table('menu_positions')->where('company_id', $cid)->where('location', 'sidebar')->whereNull('parent_key');
            // Everything after the dashboard moves one place down to make room.
            $top()->where('item_key', '!=', 'dashboard')->where('sort_order', '>=', 1)->increment('sort_order');
            DB::table('menu_positions')->where('company_id', $cid)->where('item_key', 'forms')
                ->update(['parent_key' => null, 'sort_order' => 1, 'hidden' => 0, 'location' => 'sidebar', 'updated_at' => now()]);
        });
    }

    public function down(): void
    {
        $cid = DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->value('id');
        if (! $cid) {
            return;
        }
        DB::table('menu_positions')->where('company_id', $cid)->where('item_key', 'forms')
            ->update(['parent_key' => 'custom_tc_backoffice', 'sort_order' => 7, 'updated_at' => now()]);
    }
};
