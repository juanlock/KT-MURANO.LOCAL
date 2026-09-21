<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shipping zones: a named set of destinations (country, optionally down to
     * a state) that share a list of rates.
     *
     * A location row with a NULL country is the catch-all ("Rest of the
     * world"), matching anywhere no more specific zone covers.
     */
    public function up(): void
    {
        if (! Schema::hasTable('shipping_zones')) {
            Schema::create('shipping_zones', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->string('name', 120);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('shipping_zone_locations')) {
            Schema::create('shipping_zone_locations', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->bigIncrements('id');
                $table->unsignedBigInteger('shipping_zone_id');
                // NULL country = the catch-all zone; NULL state = whole country.
                $table->string('country', 100)->nullable();
                $table->string('state', 100)->nullable();
                $table->timestamps();

                $table->foreign('shipping_zone_id')->references('id')->on('shipping_zones')->cascadeOnDelete();
                $table->index(['country', 'state']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_zone_locations');
        Schema::dropIfExists('shipping_zones');
    }
};
