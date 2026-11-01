<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Company manuals screen (2026-11-01): Settings -> Company manuals (each company's flyer
 * and text manual), install-wide (systemOnly + superAdminOnly). Its permission (the
 * SuperAdmin's role gets it through SuperAdminAccess::sync after every migrate), the
 * sidebar entry after KT-Log in every company's menu, and the texts. Safe to run twice.
 */
return new class extends Migration
{
    private const KEY = '/app/settings/company_manuals';

    private const AFTER = '/app/settings/kt_log';

    private const TEXTS = [
        'Company_Manuals' => ['Company manuals', 'Manuales por empresa'],
        'company_manuals_view' => ['Company manuals (flyers and manuals)', 'Manuales por empresa (flyers y manuales)'],
    ];

    public function up(): void
    {
        if (! DB::table('permissions')->where('name', 'company_manuals_view')->exists()) {
            DB::table('permissions')->insert(['name' => 'company_manuals_view', 'label' => 'Company manuals', 'created_at' => now(), 'updated_at' => now()]);
        }

        if (Schema::hasTable('menu_positions')) {
            foreach (DB::table('menu_positions')->where('item_key', self::AFTER)->get(['company_id', 'parent_key', 'sort_order', 'location', 'hidden']) as $ref) {
                if (DB::table('menu_positions')->where('company_id', $ref->company_id)->where('item_key', self::KEY)->exists()) {
                    continue;
                }
                $same = fn ($q) => $ref->parent_key === null ? $q->whereNull('parent_key') : $q->where('parent_key', $ref->parent_key);
                DB::table('menu_positions')->where('company_id', $ref->company_id)->where($same)->where('sort_order', '>', $ref->sort_order)->increment('sort_order');
                DB::table('menu_positions')->insert([
                    'company_id' => $ref->company_id, 'item_key' => self::KEY, 'parent_key' => $ref->parent_key, 'sort_order' => $ref->sort_order + 1,
                    'external' => 0, 'is_custom' => 0, 'hidden' => (int) $ref->hidden, 'location' => $ref->location ?: 'sidebar',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        foreach (self::TEXTS as $key => [$en, $es]) {
            TranslationKeys::ensure($key, ['es' => $es]);
            foreach (['en' => $en, 'es' => $es] as $col => $text) {
                DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$key])
                    ->where(fn ($q) => $q->whereNull($col)->orWhere($col, '')->orWhere($col, $key))
                    ->update([$col => $text, 'updated_at' => now()]);
            }
        }
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    public function down(): void
    {
    }
};
