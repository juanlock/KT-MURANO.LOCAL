<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // Null = not chosen yet; automation falls back to the
            // accounting_v2.auto_generate_journals config/env value.
            $table->boolean('auto_journal_enabled')->nullable()->after('module_flags');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('auto_journal_enabled');
        });
    }
};
