<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * KT-Log screen (2026-10-26): Settings -> KT-Log (the kt_logs history), install-wide
 * (systemOnly + superAdminOnly). Its permission (the SuperAdmin's role gets it through
 * SuperAdminAccess::sync after every migrate), the sidebar entry after System Health
 * in every company's menu, and the texts in Spanish. Safe to run twice.
 */
return new class extends Migration
{
    private const KEY = '/app/settings/kt_log';

    private const AFTER = '/app/settings/system_health';

    private const TEXTS = [
        'KT_Log' => ['KT-Log', 'KT-Log'],
        'kt_logs_view' => ['KT-Log (work history)', 'KT-Log (historial de trabajo)'],
    ];

    public function up(): void
    {
        if (Schema::hasTable('permissions') && ! DB::table('permissions')->where('name', 'kt_logs_view')->exists()) {
            DB::table('permissions')->insert(['name' => 'kt_logs_view', 'label' => 'KT-Log (work history)', 'created_at' => now(), 'updated_at' => now()]);
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

        if (Schema::hasTable('translations') && Schema::hasColumn('translations', 'es')) {
            foreach (self::TEXTS as $key => [$en, $es]) {
                TranslationKeys::ensure($key, ['es' => $es]);
                // ensure() keeps English = the key ("KT_Log"); the label wanted is "KT-Log".
                // Only while nobody has edited it (still the key).
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
    }

    public function down(): void
    {
    }
};
