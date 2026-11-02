<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Roles by company screen (user's request, 2026-11-02): Settings -> Roles by company,
 * every company x every role with its users, install-wide (systemOnly +
 * superAdminOnly). Its permission (the SuperAdmin's role gets it through
 * SuperAdminAccess::sync after every migrate), the sidebar entry after Company
 * manuals in every company's menu, and the texts. Safe to run twice.
 */
return new class extends Migration
{
    private const KEY = '/app/settings/roles_matrix';

    private const AFTER = '/app/settings/company_manuals';

    private const TEXTS = [
        'Roles_Matrix' => ['Roles by company', 'Roles por compañía'],
        'roles_matrix_view' => ['Roles by company (every company)', 'Roles por compañía (todas las compañías)'],
    ];

    public function up(): void
    {
        if (! DB::table('permissions')->where('name', 'roles_matrix_view')->exists()) {
            DB::table('permissions')->insert(['name' => 'roles_matrix_view', 'label' => 'Roles by company', 'created_at' => now(), 'updated_at' => now()]);
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
