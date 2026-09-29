<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sidebar: the "Helpdesk" module right after Store (same parent -- Store may
 * live inside an admin-made group) for every company that has a saved layout
 * (menu_positions) -- without a row a new module would land at the very end. Companies without rows use
 * config/menu.js order, where it already follows Store.
 */
return new class extends Migration
{
    private array $children = ['/app/helpdesk/dashboard', '/app/helpdesk/tickets', '/app/helpdesk/my', '/app/helpdesk/settings'];

    public function up(): void
    {
        $now = now();
        foreach (DB::table('menu_positions')->where('item_key', 'Store')->get(['company_id', 'parent_key', 'sort_order', 'location']) as $store) {
            $cid = $store->company_id;
            if (DB::table('menu_positions')->where('company_id', $cid)->where('item_key', 'helpdesk')->exists()) {
                continue;
            }
            // Same place as Store (top level, or inside the group Store was moved into).
            DB::table('menu_positions')->where('company_id', $cid)->where(fn ($q) => $store->parent_key === null ? $q->whereNull('parent_key') : $q->where('parent_key', $store->parent_key))
                ->where('sort_order', '>', $store->sort_order)->increment('sort_order');
            $rows = [[
                'company_id' => $cid, 'item_key' => 'helpdesk', 'parent_key' => $store->parent_key, 'sort_order' => $store->sort_order + 1,
                'external' => 0, 'is_custom' => 0, 'hidden' => 0, 'location' => $store->location ?: 'sidebar', 'created_at' => $now, 'updated_at' => $now,
            ]];
            foreach ($this->children as $i => $key) {
                $rows[] = [
                    'company_id' => $cid, 'item_key' => $key, 'parent_key' => 'helpdesk', 'sort_order' => $i,
                    'external' => 0, 'is_custom' => 0, 'hidden' => 0, 'location' => 'sidebar', 'created_at' => $now, 'updated_at' => $now,
                ];
            }
            DB::table('menu_positions')->insert($rows);
        }
    }

    public function down(): void
    {
        DB::table('menu_positions')->where('item_key', 'helpdesk')->orWhere('parent_key', 'helpdesk')->delete();
    }
};
