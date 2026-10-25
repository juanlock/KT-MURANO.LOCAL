<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "My round" (user's request, 2026-10-25): the doctor's / nurse's patients as
 * cards on the floor map, with the route to follow.
 *  - hms_progress_notes.kind: doctor (SOAP) or nursing (a change seen on the round);
 *  - the menu entry after My patients + Spanish.
 * Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hms_progress_notes') && ! Schema::hasColumn('hms_progress_notes', 'kind')) {
            Schema::table('hms_progress_notes', fn (Blueprint $t) => $t->string('kind', 10)->default('doctor')->after('patient_id'));
        }

        $key = '/app/hospital/rounds';
        if (Schema::hasTable('menu_positions')) {
            foreach (DB::table('menu_positions')->where('item_key', '/app/hospital/my-patients')->get(['company_id', 'parent_key', 'sort_order', 'location', 'hidden']) as $ref) {
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
            TranslationKeys::ensure('My round', ['en' => 'My round', 'es' => 'Mi ronda']);
            DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', ['My round'])->update(['es' => 'Mi ronda']);
            if (function_exists('forget_db_translations')) {
                forget_db_translations();
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('menu_positions')) {
            DB::table('menu_positions')->where('item_key', '/app/hospital/rounds')->delete();
        }
    }
};
