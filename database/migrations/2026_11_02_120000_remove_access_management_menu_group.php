<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * "Access Management" (menu group User_Management) removed for every company
 * (user's rule, 2026-11-02): its two forms -- Users and roles -- were already
 * moved to Settings, the empty group stayed and Manage Menu can't delete a
 * built-in group. menu.js now lists both forms under Settings; here the group's
 * saved position goes away and anything still inside it moves to Settings.
 * Safe to run twice.
 */
return new class extends Migration
{
    private const GROUP = 'User_Management';

    public function up(): void
    {
        if (Schema::hasTable('menu_positions')) {
            DB::table('menu_positions')->where('parent_key', self::GROUP)->update(['parent_key' => 'settings', 'updated_at' => now()]);
            DB::table('menu_positions')->where('item_key', self::GROUP)->delete();
        }

        if (! Schema::hasColumn('settings', 'sidebar_menu_order')) {
            return;
        }
        $rows = DB::table('settings')->where('sidebar_menu_order', 'like', '%"'.self::GROUP.'"%')->get(['id', 'sidebar_menu_order']);
        foreach ($rows as $row) {
            $order = json_decode($row->sidebar_menu_order, true);
            if (! is_array($order)) {
                continue;
            }
            $orphans = [];
            $order = array_values(array_filter($order, function ($entry) use (&$orphans) {
                if (($entry['id'] ?? null) !== self::GROUP) {
                    return true;
                }
                $orphans = array_merge($orphans, $entry['children'] ?? []);

                return false;
            }));
            if ($orphans) {
                foreach ($order as &$entry) {
                    if (($entry['id'] ?? null) === 'settings') {
                        $have = array_column($entry['children'] ?? [], 'id');
                        foreach ($orphans as $child) {
                            if (! in_array($child['id'] ?? null, $have, true)) {
                                $entry['children'][] = $child;
                            }
                        }
                    }
                }
                unset($entry);
            }
            DB::table('settings')->where('id', $row->id)->update(['sidebar_menu_order' => json_encode($order, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        // Nothing to restore: the group no longer exists in menu.js.
    }
};
