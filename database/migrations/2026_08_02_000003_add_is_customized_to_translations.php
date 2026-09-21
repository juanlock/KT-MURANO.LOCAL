<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks rows the admin edited through the Translations UI. TranslationSeeder
     * skips these, so re-seeding refreshes file-sourced values without ever
     * overwriting client customizations.
     */
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            if (! Schema::hasColumn('translations', 'is_customized')) {
                $table->boolean('is_customized')->default(false)->after('is_default');
            }
        });
    }

    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            if (Schema::hasColumn('translations', 'is_customized')) {
                $table->dropColumn('is_customized');
            }
        });
    }
};
