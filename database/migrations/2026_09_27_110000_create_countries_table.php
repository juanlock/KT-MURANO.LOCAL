<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reference data imported from KT-TOOLS/files/countries.xlsx (see
 * App\Console\Commands\ImportGeoData). Used to power the Country dropdown
 * on Quick Add Customer, with room for other forms to reuse it later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('iso2', 2)->unique();
            $table->string('demonym')->nullable();
            $table->string('tld', 10)->nullable();
            $table->string('currency')->nullable();
            $table->unsignedBigInteger('population')->nullable();
            $table->decimal('density', 12, 2)->nullable();
            $table->unsignedBigInteger('area')->nullable();
            $table->unsignedBigInteger('gdp')->nullable();
            $table->decimal('median_age', 5, 2)->nullable();
            $table->string('language')->nullable();
            $table->string('website')->nullable();
            $table->string('calling_code', 20)->nullable();
            $table->string('driving_side', 10)->nullable();
            $table->string('continent')->nullable();
            $table->boolean('un_member')->default(false);
            $table->string('religion')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
