<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * companies.business_type (App\Support\BusinessTypes): what the company does,
 * so Demo Data and the default accounting fit it. Known companies set:
 * KcolTech (1) IT services, Ruba Garden Landscape (2) landscaping,
 * NewEra unisex salon (3) salon. Others stay general.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'business_type')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('business_type', 30)->nullable();
            });
        }
        foreach ([1 => 'it_services', 2 => 'landscaping', 3 => 'salon'] as $id => $type) {
            DB::table('companies')->where('id', $id)->whereNull('business_type')->update(['business_type' => $type]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'business_type')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('business_type');
            });
        }
    }
};
