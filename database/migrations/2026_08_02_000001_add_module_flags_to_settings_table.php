<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'module_flags')) {
                // JSON map of module key => bool ({"hospital": false, ...}).
                // Null / missing key = module enabled. Keys match the top-level
                // ids in resources/src/config/modules.js.
                $table->longText('module_flags')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'module_flags')) {
                $table->dropColumn('module_flags');
            }
        });
    }
};
