<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expense categories: the Spanish name moves out of the category
 * (expense_categories.name_es) into the translations table -- key = the
 * English name, `es` = the Spanish text, edited in Settings -> Translations
 * like every other text -- and the form's "Name (Spanish)" field becomes
 * "Reference": a short explanation of what the category is for.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('expense_categories', 'reference')) {
            Schema::table('expense_categories', function (Blueprint $table) {
                $table->string('reference', 255)->nullable()->after('name');
            });
        }

        if (Schema::hasColumn('expense_categories', 'name_es')) {
            DB::table('expense_categories')->whereNotNull('name_es')->where('name_es', '!=', '')
                ->orderBy('id')->get(['name', 'name_es'])
                ->each(fn ($c) => TranslationKeys::ensure((string) $c->name, ['es' => (string) $c->name_es]));
            Schema::table('expense_categories', function (Blueprint $table) {
                $table->dropColumn('name_es');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('expense_categories', 'name_es')) {
            Schema::table('expense_categories', function (Blueprint $table) {
                $table->string('name_es', 192)->nullable()->after('name');
            });
            // Back from the translations table.
            if (Schema::hasColumn('translations', 'es')) {
                DB::statement('UPDATE expense_categories c JOIN translations t ON t.`key` = c.name SET c.name_es = t.es');
            }
        }
        if (Schema::hasColumn('expense_categories', 'reference')) {
            Schema::table('expense_categories', function (Blueprint $table) {
                $table->dropColumn('reference');
            });
        }
    }
};
