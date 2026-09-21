<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class TranslationSeeder extends Seeder
{
    public function run(): void
    {
        // Disable query logging for speed & memory
        DB::disableQueryLog();

        $path = database_path('seeders/translations');
        $files = File::files($path);

        // Rows edited by the client through the Translations UI are flagged
        // is_customized and must never be overwritten by re-seeding. Keys are
        // compared case-sensitively (the column is utf8mb4_bin).
        // On a fresh install this seeder runs from inside the migration that
        // creates the table, before the is_customized column is added — no
        // customizations can exist at that point, so skip the lookup.
        $customized = [];
        if (Schema::hasColumn('translations', 'is_customized')) {
            foreach (DB::table('translations')->where('is_customized', 1)->get(['locale', 'key']) as $row) {
                $customized[$row->locale . "\0" . $row->key] = true;
            }
        }

        $allTranslations = [];
        $skipped = 0;

        foreach ($files as $file) {
            $locale = pathinfo($file, PATHINFO_FILENAME); // 'en', 'ar', etc.
            $translations = require $file;

            foreach ($translations as $key => $value) {
                if (isset($customized[$locale . "\0" . $key])) {
                    $skipped++;
                    continue;
                }
                $allTranslations[] = [
                    'locale' => $locale,
                    'key' => $key,
                    'value' => $value,
                    'is_default' => $locale === 'en' ? 1 : 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($allTranslations, 1000) as $chunk) {
            DB::table('translations')->upsert(
                $chunk,
                ['locale', 'key'],
                ['value', 'is_default', 'updated_at']
            );
        }

        if ($skipped > 0) {
            $this->command?->info("Preserved {$skipped} client-customized translation(s).");
        }
    }
}
