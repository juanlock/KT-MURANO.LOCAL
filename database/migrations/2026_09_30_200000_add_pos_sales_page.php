<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * POS Management -> POS Sales (/next/pos-sales): the Sales list narrowed to
 * POS sales. Adds its label (en/es) and places the menu item in every
 * company's custom "POS Management" group, right after the POS launcher.
 */
return new class extends Migration
{
    private const ITEM = '/app/pos/sales';

    public function up(): void
    {
        if (! DB::table('translations')->whereRaw('BINARY `key` = ?', ['POS_Sales'])->exists()) {
            DB::table('translations')->insert([
                'key' => 'POS_Sales', 'is_default' => 1, 'en' => 'POS Sales', 'es' => 'Ventas POS',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach (DB::table('menu_positions')->where('item_key', 'custom_pos')->pluck('company_id') as $companyId) {
            if (DB::table('menu_positions')->where('company_id', $companyId)->where('item_key', self::ITEM)->exists()) {
                continue;
            }
            DB::table('menu_positions')->where('company_id', $companyId)->where('parent_key', 'custom_pos')
                ->where('sort_order', '>=', 1)->increment('sort_order');
            DB::table('menu_positions')->insert([
                'company_id' => $companyId, 'item_key' => self::ITEM, 'parent_key' => 'custom_pos',
                'is_custom' => 0, 'hidden' => 0, 'sort_order' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    public function down(): void
    {
        foreach (DB::table('menu_positions')->where('item_key', self::ITEM)->get(['company_id', 'parent_key']) as $row) {
            DB::table('menu_positions')->where('company_id', $row->company_id)->where('item_key', self::ITEM)->delete();
            if ($row->parent_key === 'custom_pos') {
                DB::table('menu_positions')->where('company_id', $row->company_id)->where('parent_key', 'custom_pos')
                    ->where('sort_order', '>', 1)->decrement('sort_order');
            }
        }
        DB::table('translations')->whereRaw('BINARY `key` = ?', ['POS_Sales'])->delete();
    }
};
