<?php

use App\Support\BusinessTypes;
use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;

/**
 * Spanish names of every business type's expense categories (laundry, delivery,
 * call center, real estate, law, hospital, factory, school... 2026-10-19/20).
 * BusinessTypes::ensure() creates them when a company of that type is set up,
 * but production got those companies through the data push, which never sends
 * translations. By key (the English name), only empty cells are filled:
 * safe to run twice, never overwrites a hand-edited translation.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (BusinessTypes::categoryTranslations() as $name => $es) {
            TranslationKeys::ensure($name, ['es' => $es]);
        }
    }

    public function down(): void
    {
        // Translations are kept (other rows may use the same keys).
    }
};
