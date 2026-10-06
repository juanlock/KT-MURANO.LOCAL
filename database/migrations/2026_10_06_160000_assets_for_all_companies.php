<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assets for every company (user's request, 2026-10-06):
 *  - module flag `assets` on for every company;
 *  - the Assets menu right after Products (menu_positions + sidebar_menu_order);
 *  - asset categories per company (company_id; unique name per company, was
 *    install-wide) -- assets already belong to a company through their
 *    location. Existing categories take the company of the assets using them.
 *  - Hartfield Titus and Donnelly (1003, law office): business type
 *    "professional" (office), so its demo data fits.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('settings')->get(['id', 'module_flags']) as $s) {
            $flags = json_decode((string) $s->module_flags, true);
            if (is_array($flags)) {
                $flags['assets'] = true;
                DB::table('settings')->where('id', $s->id)->update(['module_flags' => json_encode($flags)]);
            }
        }

        // menu_positions: Assets right after Products among the top-level items.
        foreach (DB::table('menu_positions')->whereNull('parent_key')->distinct()->pluck('company_id') as $cid) {
            $rows = DB::table('menu_positions')->where('company_id', $cid)->whereNull('parent_key')->orderBy('sort_order')->orderBy('id')->get(['id', 'item_key']);
            $keys = $rows->pluck('item_key')->all();
            if (! in_array('products', $keys, true) || ! in_array('assets', $keys, true)) {
                continue;
            }
            $order = array_values(array_filter($rows->all(), fn ($r) => $r->item_key !== 'assets'));
            $assets = $rows->firstWhere('item_key', 'assets');
            $at = array_search('products', array_map(fn ($r) => $r->item_key, $order), true);
            array_splice($order, $at + 1, 0, [$assets]);
            foreach ($order as $i => $r) {
                DB::table('menu_positions')->where('id', $r->id)->update(['sort_order' => $i]);
            }
        }
        // sidebar_menu_order (older saved order): same move at the top level.
        foreach (DB::table('settings')->get(['id', 'sidebar_menu_order']) as $s) {
            $o = json_decode((string) $s->sidebar_menu_order, true);
            if (! is_array($o)) {
                continue;
            }
            $ids = array_map(fn ($x) => $x['id'] ?? null, $o);
            $ai = array_search('assets', $ids, true);
            $pi = array_search('products', $ids, true);
            if ($ai === false || $pi === false) {
                continue;
            }
            $assets = $o[$ai];
            array_splice($o, $ai, 1);
            $pi = array_search('products', array_map(fn ($x) => $x['id'] ?? null, $o), true);
            array_splice($o, $pi + 1, 0, [$assets]);
            DB::table('settings')->where('id', $s->id)->update(['sidebar_menu_order' => json_encode($o)]);
        }

        // Asset categories per company.
        if (! Schema::hasColumn('asset_categories', 'company_id')) {
            Schema::table('asset_categories', function (Blueprint $t) {
                $t->unsignedBigInteger('company_id')->nullable()->after('id')->index();
            });
        }
        foreach (DB::table('asset_categories')->whereNull('company_id')->pluck('id') as $catId) {
            $companies = DB::table('assets as a')->join('warehouses as w', 'w.id', '=', 'a.warehouse_id')
                ->where('a.asset_category_id', $catId)->distinct()->pluck('w.company_id');
            $tagged = DB::table('demo_records')->where('record_type', 'asset_categories')->where('record_id', $catId)->value('company_id');
            $cid = $tagged ?: ($companies->count() === 1 ? $companies->first() : null);
            if ($cid) {
                DB::table('asset_categories')->where('id', $catId)->update(['company_id' => $cid]);
            }
        }
        $indexes = array_map(fn ($i) => $i->Key_name, DB::select('SHOW INDEX FROM asset_categories'));
        Schema::table('asset_categories', function (Blueprint $t) use ($indexes) {
            if (in_array('asset_categories_name_unique', $indexes, true)) {
                $t->dropUnique('asset_categories_name_unique');
            }
            if (! in_array('asset_categories_company_name_unique', $indexes, true)) {
                $t->unique(['company_id', 'name'], 'asset_categories_company_name_unique');
            }
        });

        if (Schema::hasColumn('companies', 'business_type')) {
            DB::table('companies')->where('id', 1003)->whereNull('business_type')->update(['business_type' => 'professional']);
        }
    }

    public function down(): void
    {
        Schema::table('asset_categories', function (Blueprint $t) {
            $t->dropUnique('asset_categories_company_name_unique');
        });
        Schema::table('asset_categories', function (Blueprint $t) {
            $t->dropColumn('company_id');
        });
    }
};
