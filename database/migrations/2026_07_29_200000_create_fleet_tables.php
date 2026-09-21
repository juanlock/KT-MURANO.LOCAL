<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vehicle & Fleet Management: the vehicle register plus the three logs a
     * fleet actually runs on — maintenance, fuel and driver assignments.
     *
     * Vehicle photos go to public/images/vehicles, the same convention as the
     * rest of the app's uploads; `image` holds the file name only.
     *
     * No DB-level foreign keys (warehouses/employees are referenced by id),
     * matching the promotions/contracts/documents convention here — integrity
     * is enforced at the app layer.
     */
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');

            $table->string('name', 191);
            $table->string('plate_number', 64)->index();
            $table->string('vin', 64)->nullable();
            $table->string('make', 64)->nullable();
            $table->string('model', 64)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('color', 40)->nullable();

            $table->enum('type', ['car', 'van', 'truck', 'bus', 'motorcycle', 'forklift', 'trailer', 'other'])
                ->default('car')->index();
            $table->enum('status', ['active', 'maintenance', 'inactive', 'sold'])
                ->default('active')->index();

            // Which branch the vehicle belongs to, and who currently drives it.
            $table->integer('warehouse_id')->nullable()->index();
            $table->integer('employee_id')->nullable()->index();

            $table->enum('fuel_type', ['petrol', 'diesel', 'electric', 'hybrid', 'lpg', 'cng'])
                ->default('petrol');
            $table->decimal('tank_capacity', 8, 2)->nullable();
            // Current reading; every fuel log / maintenance entry can push it up.
            $table->decimal('odometer', 12, 2)->default(0);

            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_price', 14, 2)->nullable();

            // Renewal dates the dashboard warns about.
            $table->string('insurance_provider', 191)->nullable();
            $table->string('insurance_policy', 100)->nullable();
            $table->date('insurance_expiry')->nullable()->index();
            $table->date('registration_expiry')->nullable()->index();
            $table->date('inspection_expiry')->nullable()->index();

            $table->string('image', 191)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps(6);
            $table->softDeletes();
        });

        Schema::create('vehicle_maintenances', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('vehicle_id')->index();

            $table->enum('type', ['service', 'repair', 'tyres', 'inspection', 'insurance', 'other'])
                ->default('service')->index();
            $table->string('title', 191);
            $table->date('service_date')->index();
            $table->decimal('odometer', 12, 2)->nullable();
            $table->decimal('cost', 14, 2)->default(0);
            $table->string('vendor', 191)->nullable();
            $table->enum('status', ['scheduled', 'in_progress', 'completed'])
                ->default('completed')->index();

            // What the "service due" alert reads.
            $table->date('next_service_date')->nullable()->index();
            $table->decimal('next_service_odometer', 12, 2)->nullable();

            $table->text('notes')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });

        Schema::create('vehicle_fuel_logs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('vehicle_id')->index();
            $table->integer('employee_id')->nullable()->index();

            $table->date('log_date')->index();
            $table->decimal('odometer', 12, 2)->default(0);
            $table->decimal('quantity', 10, 2)->default(0);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->string('station', 191)->nullable();
            // Consumption maths is only valid between full tanks.
            $table->boolean('full_tank')->default(true);

            $table->text('notes')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });

        Schema::create('vehicle_assignments', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('vehicle_id')->index();
            $table->integer('employee_id')->index();

            $table->dateTime('start_date')->index();
            $table->dateTime('end_date')->nullable();
            $table->decimal('start_odometer', 12, 2)->nullable();
            $table->decimal('end_odometer', 12, 2)->nullable();

            $table->string('purpose', 191)->nullable();
            $table->string('destination', 191)->nullable();
            $table->enum('status', ['active', 'completed', 'cancelled'])
                ->default('active')->index();

            $table->text('notes')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps(6);
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_assignments');
        Schema::dropIfExists('vehicle_fuel_logs');
        Schema::dropIfExists('vehicle_maintenances');
        Schema::dropIfExists('vehicles');
    }
};
