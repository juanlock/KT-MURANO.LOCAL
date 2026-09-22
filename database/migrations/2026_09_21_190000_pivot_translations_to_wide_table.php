<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from KT-Billing's own 2026_09_21_180000_pivot_translations_to_wide_table
 * (the exact same problem, solved there first): the old translations table
 * stored one row per (locale, key) pair, so a key existed for a locale only
 * if something inserted a matching row for it -- keys drift out of sync
 * across locales over time. This pivots to one row per KEY, one column per
 * locale: a key either has a row or it doesn't, and a locale either has a
 * value in its own column or it's empty.
 *
 * Two differences from Billing's version, both required by things Billing
 * doesn't have:
 *
 * - `customized_locales`: Billing's wide table dropped `is_customized`
 *   entirely (it never had a live consumer for it). Murano's
 *   TranslationSeeder actively depends on per-(locale,key) customization
 *   tracking -- it runs on EVERY production auto-update
 *   (PostUpdateTasks::run / AutoUpdateController::runPostUpdateTasks) and
 *   must not clobber a client's manual edit. The old schema tracked that per
 *   row; the new one needs it per locale-within-a-key, so it becomes a
 *   comma-separated list of customized locale codes on that key's row
 *   (e.g. "en,fr") instead of a single boolean column.
 * - Key casing is normalized (lowercased+trimmed grouping key, `en`'s own
 *   casing wins as canonical) even though this column is `utf8mb4_bin`
 *   (case-sensitive) here, unlike Billing's case-insensitive collation --
 *   confirmed 24 real cross-locale casing splits in production data before
 *   writing this (e.g. `en` has "Brands", every other locale has "brands" --
 *   without merging, the pivot would produce two separate key rows, one with
 *   no English value and one with no translations at all for anything else).
 */
return new class extends Migration
{
    public function up(): void
    {
        $reserved = ['id', 'key', 'is_default', 'customized_locales', 'created_at', 'updated_at'];

        $locales = DB::table('translations')->distinct()->pluck('locale')
            ->filter(fn ($locale) => $locale && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $locale) && ! in_array(strtolower($locale), $reserved, true))
            ->values();

        Schema::create('translations_new', function (Blueprint $table) use ($locales) {
            $table->id();
            $table->string('key')->unique();
            $table->boolean('is_default')->default(false);
            $table->text('customized_locales')->nullable();
            foreach ($locales as $locale) {
                $table->text($locale)->nullable();
            }
            $table->timestamps(6);
        });

        $template = array_fill_keys($locales->all(), null);
        $rows = []; // normalized (lowercase) key => row data
        $customized = []; // normalized key => [locale => true]

        DB::table('translations')->orderBy('key')->chunk(2000, function ($chunk) use (&$rows, &$customized, $template) {
            foreach ($chunk as $row) {
                $normalized = mb_strtolower(trim($row->key));

                if (! isset($rows[$normalized])) {
                    $rows[$normalized] = array_merge($template, [
                        'key' => trim($row->key),
                        'is_default' => false,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ]);
                } elseif ($row->locale === 'en') {
                    $rows[$normalized]['key'] = trim($row->key);
                }

                if (in_array($row->locale, array_keys($template), true)) {
                    $rows[$normalized][$row->locale] = $row->value;
                }
                if ($row->is_default) {
                    $rows[$normalized]['is_default'] = true;
                }
                if ($row->is_customized) {
                    $customized[$normalized][$row->locale] = true;
                }
            }
        });

        foreach ($customized as $normalized => $locales2) {
            $rows[$normalized]['customized_locales'] = implode(',', array_keys($locales2));
        }

        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            DB::table('translations_new')->insert($chunk);
        }

        Schema::drop('translations');
        Schema::rename('translations_new', 'translations');
    }

    public function down(): void
    {
        // One-directional by design -- see the class doc comment.
    }
};
