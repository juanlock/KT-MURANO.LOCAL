<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Real Estate (2026-10-21, "Quiero ese Inmueble"):
 *  - Spanish for the dashboard texts that had none (by key; a missing key is
 *    inserted, an empty / still-English Spanish cell filled, a hand-edited one
 *    kept); "Category" was "category" / "categoría" in lowercase.
 *  - The Real Estate Dashboard menu entry was missing from the saved menus
 *    (it was added after them, so it showed last): it goes first in its group
 *    in every company's saved menu.
 * Safe to run twice.
 */
return new class extends Migration
{
    private const TEXTS = [
        'Real_Estate_Dashboard' => ['Real Estate dashboard', 'Panel inmobiliario'],
        'New_inquiries' => ['New inquiries', 'Consultas nuevas'],
        'By_purpose' => ['By purpose', 'Por propósito'],
        'Latest_properties' => ['Latest properties', 'Últimas propiedades'],
        'Latest_inquiries' => ['Latest inquiries', 'Últimas consultas'],
        'No_inquiries_yet' => ['No inquiries yet', 'Aún no hay consultas'],
        'Rent' => ['Rent', 'Alquiler'],
        'Add_Property' => ['Add property', 'Añadir propiedad'],
        'Real Estate Dashboard' => ['Real Estate Dashboard', 'Panel inmobiliario'],
    ];

    public function up(): void
    {
        if (Schema::hasTable('translations') && Schema::hasColumn('translations', 'es')) {
            foreach (self::TEXTS as $key => [$en, $es]) {
                TranslationKeys::ensure($key, ['en' => $en, 'es' => $es]);
                $row = DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$key])->first();
                if ($row && trim((string) $row->es) === trim((string) ($row->en ?: $key))) {
                    DB::table('translations')->where('id', $row->id)->update(['es' => $es, 'updated_at' => now()]);
                }
            }
            DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', ['Category'])->where('en', 'category')->update(['en' => 'Category', 'updated_at' => now()]);
            DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', ['Category'])->where('es', 'categoría')->update(['es' => 'Categoría', 'updated_at' => now()]);
            if (function_exists('forget_db_translations')) {
                forget_db_translations();
            }
        }

        if (Schema::hasTable('menu_positions')) {
            $key = '/app/realestate/dashboard';
            $groups = DB::table('menu_positions')->where('item_key', '/app/realestate/properties')->get(['company_id', 'parent_key', 'location', 'hidden']);
            foreach ($groups as $g) {
                if (DB::table('menu_positions')->where('company_id', $g->company_id)->where('item_key', $key)->exists()) {
                    continue;
                }
                DB::transaction(function () use ($g, $key) {
                    $sameParent = fn ($q) => $g->parent_key === null ? $q->whereNull('parent_key') : $q->where('parent_key', $g->parent_key);
                    DB::table('menu_positions')->where('company_id', $g->company_id)->where($sameParent)->increment('sort_order');
                    DB::table('menu_positions')->insert([
                        'company_id' => $g->company_id, 'item_key' => $key, 'parent_key' => $g->parent_key, 'sort_order' => 0,
                        'external' => 0, 'is_custom' => 0, 'hidden' => (int) $g->hidden, 'location' => $g->location ?: 'sidebar',
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                });
            }
        }
    }

    public function down(): void
    {
    }
};
