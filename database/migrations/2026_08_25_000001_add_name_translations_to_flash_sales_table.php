<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-language campaign names: {"fr": "Vente d'été", "es": "Venta de Verano"}.
     * `name` stays the admin-facing / fallback title.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('flash_sales', 'name_translations')) {
            Schema::table('flash_sales', function (Blueprint $table) {
                $table->json('name_translations')->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('flash_sales', 'name_translations')) {
            Schema::table('flash_sales', function (Blueprint $table) {
                $table->dropColumn('name_translations');
            });
        }
    }
};
