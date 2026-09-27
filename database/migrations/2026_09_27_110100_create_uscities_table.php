<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reference data imported from KT-TOOLS/files/uscities.xlsx (see
 * App\Console\Commands\ImportGeoData). Powers the City dropdown on Quick
 * Add Customer once Country = United States, with room for other forms to
 * reuse it later. Indexed on city/state for the remote-search picker
 * (31k+ rows -- never loaded as a full dropdown).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uscities', function (Blueprint $table) {
            $table->id();
            // The source dataset's own numeric id (simplemaps uscities) --
            // kept for reference only, not used as our primary key.
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('city');
            $table->string('city_ascii');
            $table->string('state_id', 2);
            $table->string('state_name');
            $table->unsignedInteger('county_fips')->nullable();
            $table->string('county_name')->nullable();
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();
            $table->unsignedBigInteger('population')->nullable();
            $table->decimal('density', 12, 2)->nullable();
            $table->boolean('military')->default(false);
            $table->boolean('incorporated')->default(false);
            $table->string('timezone')->nullable();
            $table->unsignedTinyInteger('ranking')->nullable();
            $table->text('zips')->nullable();
            $table->timestamps();

            $table->index('city');
            $table->index('state_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uscities');
    }
};
