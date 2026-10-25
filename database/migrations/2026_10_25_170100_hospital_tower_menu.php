<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Hospital phase 3 (2026-10-25): "Control tower" in every company's side menu (after Wards & Beds) + Spanish. Safe to run twice. */
return new class extends Migration
{
    public function up(): void
    {
        $key = '/app/hospital/control-tower';
        if (Schema::hasTable('menu_positions')) {
            foreach (DB::table('menu_positions')->where('item_key', '/app/hospital/wards')->get(['company_id', 'parent_key', 'sort_order', 'location', 'hidden']) as $ref) {
                if (DB::table('menu_positions')->where('company_id', $ref->company_id)->where('item_key', $key)->exists()) {
                    continue;
                }
                $same = fn ($q) => $ref->parent_key === null ? $q->whereNull('parent_key') : $q->where('parent_key', $ref->parent_key);
                DB::table('menu_positions')->where('company_id', $ref->company_id)->where($same)->where('sort_order', '>', $ref->sort_order)->increment('sort_order');
                DB::table('menu_positions')->insert([
                    'company_id' => $ref->company_id, 'item_key' => $key, 'parent_key' => $ref->parent_key, 'sort_order' => $ref->sort_order + 1,
                    'external' => 0, 'is_custom' => 0, 'hidden' => (int) $ref->hidden, 'location' => $ref->location ?: 'sidebar',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        if (Schema::hasTable('translations') && Schema::hasColumn('translations', 'es')) {
            TranslationKeys::ensure('Control tower', ['es' => 'Torre de control']);
            $row = DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', ['Control tower'])->first();
            if ($row && trim((string) $row->es) === trim((string) ($row->en ?: 'Control tower'))) {
                DB::table('translations')->where('id', $row->id)->update(['es' => 'Torre de control', 'updated_at' => now()]);
            }
            if (function_exists('forget_db_translations')) {
                forget_db_translations();
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('menu_positions')) {
            DB::table('menu_positions')->where('item_key', '/app/hospital/control-tower')->delete();
        }
    }
};
