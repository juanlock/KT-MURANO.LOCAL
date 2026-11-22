<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A link's preview (platform, title, description, picture) kept with it (2026-11-22). Safe to run twice. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('project_documents', 'meta')) {
            Schema::table('project_documents', function (Blueprint $t) {
                $t->text('meta')->nullable()->after('url');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('project_documents', 'meta')) {
            Schema::table('project_documents', fn (Blueprint $t) => $t->dropColumn('meta'));
        }
    }
};
