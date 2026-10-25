<?php

use App\Support\BusinessTypes;
use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;

/**
 * Spanish names of the business types' expense categories, again (2026-10-24):
 * adds the new Tourism / travel agency type's (tour and package costs, tourist
 * transport, tourism licenses). By key, only empty cells are filled: safe to
 * run twice, never overwrites a hand-edited translation.
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
        // Translations are kept.
    }
};
