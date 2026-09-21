<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_makes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('vehicle_models', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehicle_make_id')->index();
            $table->string('name', 100);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['vehicle_make_id', 'name']);
        });

        // A product with NO fitment rows is universal (fits every vehicle).
        // vehicle_model_id NULL = fits all models of the make; year bounds and
        // engine are optional narrowing constraints.
        Schema::create('product_fitments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('vehicle_make_id');
            $table->unsignedBigInteger('vehicle_model_id')->nullable();
            $table->unsignedSmallInteger('year_from')->nullable();
            $table->unsignedSmallInteger('year_to')->nullable();
            $table->string('engine', 100)->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->index(['vehicle_make_id', 'vehicle_model_id']);
        });

        // "My Garage": vehicles saved by a storefront customer (via the linked
        // Client). Guests keep their selection in the session/localStorage only.
        Schema::create('customer_vehicles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->index();
            $table->unsignedBigInteger('vehicle_make_id');
            $table->unsignedBigInteger('vehicle_model_id');
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('engine', 100)->nullable();
            $table->string('nickname', 100)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'vehicle_fitment_enabled')) {
                $table->boolean('vehicle_fitment_enabled')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_vehicles');
        Schema::dropIfExists('product_fitments');
        Schema::dropIfExists('vehicle_models');
        Schema::dropIfExists('vehicle_makes');
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'vehicle_fitment_enabled')) {
                $table->dropColumn('vehicle_fitment_enabled');
            }
        });
    }
};
