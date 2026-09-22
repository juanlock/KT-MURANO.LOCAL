<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class TranslationSeeder extends Seeder
{
    /**
     * Runs on EVERY production auto-update (see
     * app\Services\Updater\PostUpdateTasks and AutoUpdateController), so it
     * must never regress a live install's data even if the schema underneath
     * it changes shape.
     *
     * Since 2026_09_21_190000_pivot_translations_to_wide_table, `translations`
     * is one row per KEY with one column per locale (previously one row per
     * (locale, key) pair) -- so re-seeding now upserts a whole row spanning
     * every locale at once, instead of one independent row per locale.
     *
     * A client's manual edit (Settings -> Translations) is protected per
     * (key, locale), tracked in the new `customized_locales` column (a
     * comma-separated locale list on that key's row) -- the old
     * `is_customized` boolean was per (locale,key) row, which no longer
     * exists as a concept. A protected cell is simply left out of this run's
     * VALUES for that key/locale, and the raw upsert below uses
     * `COALESCE(VALUES(col), col)` on every locale column so a column with no
     * new value (protected, OR just absent from that locale's shipped file)
     * keeps whatever is already in the database instead of being nulled out
     * -- ordinary Laravel upsert() can't express that (it applies one
     * `col = VALUES(col)` uniformly across the whole batch), which is why
     * this builds the SQL directly.
     */
    public function run(): void
    {
        DB::disableQueryLog();

        $path = database_path('seeders/translations');
        $files = File::files($path);

        $locales = collect($files)
            ->map(fn ($file) => pathinfo($file, PATHINFO_FILENAME))
            ->filter(fn ($locale) => Schema::hasColumn('translations', $locale))
            ->values()
            ->all();

        if (empty($locales)) {
            return;
        }

        // key => [locale => true] -- cells a client edited through the
        // Translations UI, which this run must not overwrite.
        $customized = [];
        if (Schema::hasColumn('translations', 'customized_locales')) {
            foreach (DB::table('translations')->whereNotNull('customized_locales')->get(['key', 'customized_locales']) as $row) {
                foreach (explode(',', $row->customized_locales) as $loc) {
                    if ($loc !== '') {
                        $customized[$row->key][$loc] = true;
                    }
                }
            }
        }

        // key => [locale => value] -- only cells this run actually has a
        // shipped value for (never null placeholders), so nothing here can
        // accidentally clobber a customization or a locale-file gap.
        $rows = [];
        $skipped = 0;

        foreach ($files as $file) {
            $locale = pathinfo($file, PATHINFO_FILENAME);
            if (! in_array($locale, $locales, true)) {
                continue;
            }

            $translations = require $file;
            foreach ($translations as $key => $value) {
                if (isset($customized[$key][$locale])) {
                    $skipped++;

                    continue;
                }
                $rows[$key][$locale] = $value;
            }
        }

        $now = now();
        foreach (array_chunk(array_keys($rows), 200) as $keyChunk) {
            $this->upsertChunk($keyChunk, $rows, $locales, $now);
        }

        if ($skipped > 0) {
            $this->command?->info("Preserved {$skipped} client-customized translation cell(s).");
        }
    }

    /** @param  array<int, string>  $keys */
    private function upsertChunk(array $keys, array $rows, array $locales, $now): void
    {
        $columns = array_merge(['key', 'is_default'], $locales, ['created_at', 'updated_at']);
        $placeholders = '('.implode(', ', array_fill(0, count($columns), '?')).')';

        $valuesSql = implode(', ', array_fill(0, count($keys), $placeholders));
        $bindings = [];

        foreach ($keys as $key) {
            $cells = $rows[$key];
            $bindings[] = $key;
            $bindings[] = isset($cells['en']) ? 1 : 0;
            foreach ($locales as $locale) {
                $bindings[] = $cells[$locale] ?? null;
            }
            $bindings[] = $now;
            $bindings[] = $now;
        }

        $updateClauses = ['is_default = is_default OR VALUES(is_default)'];
        foreach ($locales as $locale) {
            $updateClauses[] = "`{$locale}` = COALESCE(VALUES(`{$locale}`), `{$locale}`)";
        }
        $updateClauses[] = 'updated_at = VALUES(updated_at)';

        $columnList = implode(', ', array_map(fn ($c) => "`{$c}`", $columns));

        DB::statement(
            "INSERT INTO `translations` ({$columnList}) VALUES {$valuesSql} ".
            'ON DUPLICATE KEY UPDATE '.implode(', ', $updateClauses),
            $bindings
        );
    }
}
