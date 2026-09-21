<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            if (! Schema::hasColumn('units', 'is_active')) {
                // Matches the Unit model (fillable + integer cast) and the
                // convention used by the products table.
                $table->boolean('is_active')->nullable()->default(1)->after('operator_value');
            }
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            if (Schema::hasColumn('units', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }
};
