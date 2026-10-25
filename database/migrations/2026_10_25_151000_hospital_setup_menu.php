<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hospital phase 1 (2026-10-25): side-menu entries (every company's Manage
 * Menu, next to an existing hospital entry) and Spanish texts. Safe to run twice.
 */
return new class extends Migration
{
    /** [new item, the item it goes after] */
    private const MENU = [
        ['/app/hospital/setup', '/app/hospital/departments'],
    ];

    private const TEXTS = [
        'Hospital configuration' => 'Configuración del hospital',
    ];

    public function up(): void
    {
        if (Schema::hasTable('menu_positions')) {
            foreach (self::MENU as [$key, $after]) {
                foreach (DB::table('menu_positions')->where('item_key', $after)->get(['company_id', 'parent_key', 'sort_order', 'location', 'hidden']) as $ref) {
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
        }
        if (Schema::hasTable('translations') && Schema::hasColumn('translations', 'es')) {
            foreach (self::TEXTS as $key => $es) {
                TranslationKeys::ensure($key, ['es' => $es]);
                $row = DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$key])->first();
                if ($row && trim((string) $row->es) === trim((string) ($row->en ?: $key))) {
                    DB::table('translations')->where('id', $row->id)->update(['es' => $es, 'updated_at' => now()]);
                }
            }
            if (function_exists('forget_db_translations')) {
                forget_db_translations();
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('menu_positions')) {
            DB::table('menu_positions')->whereIn('item_key', array_column(self::MENU, 0))->delete();
        }
    }
};
