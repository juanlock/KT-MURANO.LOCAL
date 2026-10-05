<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data names that are translated through the translations table (Settings ->
 * Translations), e.g. expense category names: the English name is the key,
 * each language's text sits in its locale column. The frontend shows them
 * with lib/localName (falls back to the name itself when untranslated).
 */
class TranslationKeys
{
    /**
     * Make sure $key exists ('en' = the key itself). $values (locale => text)
     * only fill EMPTY cells, so an existing translation -- often edited by
     * hand in Settings -> Translations -- is never overwritten. Those filled
     * cells are flagged customized so TranslationSeeder leaves them alone.
     */
    public static function ensure(string $key, array $values = []): void
    {
        $key = trim($key);
        if ($key === '' || mb_strlen($key) > 255 || ! Schema::hasTable('translations')) {
            return;
        }
        $values = array_filter(['en' => $key] + $values, fn ($v, $locale) => is_string($v) && trim($v) !== '' && Schema::hasColumn('translations', $locale), ARRAY_FILTER_USE_BOTH);

        $row = DB::table('translations')->where('key', $key)->first();
        if (! $row) {
            DB::table('translations')->insert(['key' => $key, 'is_default' => 0, 'customized_locales' => implode(',', array_keys($values)), 'created_at' => now(), 'updated_at' => now()] + $values);
            self::forget(array_keys($values));

            return;
        }
        $fill = array_filter($values, fn ($v, $locale) => $row->{$locale} === null || trim((string) $row->{$locale}) === '', ARRAY_FILTER_USE_BOTH);
        if (! $fill) {
            return;
        }
        $customized = array_values(array_unique(array_merge(array_filter(explode(',', (string) $row->customized_locales)), array_keys($fill))));
        DB::table('translations')->where('id', $row->id)->update($fill + ['customized_locales' => implode(',', $customized), 'updated_at' => now()]);
        self::forget(array_keys($fill));
    }

    private static function forget(array $locales): void
    {
        if (function_exists('forget_db_translations')) {
            foreach ($locales as $locale) {
                forget_db_translations($locale);
            }
        }
    }
}
