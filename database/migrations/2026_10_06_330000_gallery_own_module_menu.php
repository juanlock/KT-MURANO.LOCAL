<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gallery is its own module (user's choice, 2026-10-06): its own sidebar entry
 * (right after Website Management where a company saved its order) instead of
 * Website Management -> Gallery, and its own Business Modules switch (off where
 * Website Management was off). The pictures already live in
 * files/company_{id}/gallery/ -- nothing to move.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('menu_positions')) {
            DB::table('menu_positions')->where('item_key', '/app/website/gallery')->delete();
            foreach (DB::table('menu_positions')->where('item_key', 'website')->get() as $w) {
                if (DB::table('menu_positions')->where('company_id', $w->company_id)->where('item_key', 'gallery')->exists()) {
                    continue;
                }
                $siblings = DB::table('menu_positions')->where('company_id', $w->company_id)->where('sort_order', '>', $w->sort_order);
                $w->parent_key === null ? $siblings->whereNull('parent_key')->increment('sort_order') : $siblings->where('parent_key', $w->parent_key)->increment('sort_order');
                DB::table('menu_positions')->insert([
                    'company_id' => $w->company_id, 'item_key' => 'gallery', 'parent_key' => $w->parent_key, 'label' => null,
                    'is_custom' => 0, 'hidden' => (int) $w->hidden, 'location' => $w->location ?? 'sidebar',
                    'sort_order' => $w->sort_order + 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        if (Schema::hasColumn('settings', 'module_flags')) {
            foreach (DB::table('settings')->whereNotNull('module_flags')->get(['id', 'module_flags']) as $row) {
                $flags = json_decode((string) $row->module_flags, true) ?: [];
                if (($flags['website'] ?? true) === false && ! array_key_exists('gallery', $flags)) {
                    $flags['gallery'] = false;
                    DB::table('settings')->where('id', $row->id)->update(['module_flags' => json_encode($flags)]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('menu_positions')) {
            DB::table('menu_positions')->where('item_key', 'gallery')->delete();
        }
    }
};
