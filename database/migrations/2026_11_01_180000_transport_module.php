<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Transport module (user's request, 2026-11-01, Turismo Cautivo): locations
 * (terminals / agencies), routes between them, trips (a vehicle, 2 pilots, 1 or 2
 * hostesses) and their crew; fleet maintenance done in house or by a workshop
 * (a supplier); employees with a staff type (administrative, maintenance, pilot,
 * hostess) and pay per trip. Module "transport", off by default
 * (ModuleFlags::DEFAULT_OFF). Permissions, the menu group after Fleet in every
 * company's menu and the texts. Safe to run twice.
 */
return new class extends Migration
{
    private const PERMS = ['transport_view' => 'Transport: see trips, routes and crew', 'transport_manage' => 'Transport: plan trips, routes and locations'];

    private const ITEMS = ['/app/transport/trips', '/app/transport/routes', '/app/transport/staff'];

    private const TEXTS = [
        'Transport' => ['Transport', 'Transporte'],
        'Transport_Trips' => ['Trips', 'Viajes'],
        'Transport_Routes' => ['Routes and locations', 'Rutas y locales'],
        'Transport_Staff' => ['Active staff', 'Personal activo'],
        'transport_view' => ['Transport: see trips, routes and crew', 'Transporte: ver viajes, rutas y tripulación'],
        'transport_manage' => ['Transport: plan trips, routes and locations', 'Transporte: programar viajes, rutas y locales'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('transport_locations')) {
            Schema::create('transport_locations', function (Blueprint $t) {
                $t->increments('id');
                $t->integer('company_id')->index();
                $t->string('name', 120);
                $t->string('city', 120)->nullable();
                $t->string('kind', 20)->default('agency'); // terminal | agency | online
                $t->boolean('active')->default(true);
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('transport_routes')) {
            Schema::create('transport_routes', function (Blueprint $t) {
                $t->increments('id');
                $t->integer('company_id')->index();
                $t->unsignedInteger('origin_id');
                $t->unsignedInteger('destination_id');
                $t->decimal('distance_km', 8, 1)->nullable();
                $t->unsignedInteger('duration_min')->nullable();
                $t->boolean('active')->default(true);
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('transport_trips')) {
            Schema::create('transport_trips', function (Blueprint $t) {
                $t->increments('id');
                $t->integer('company_id')->index();
                $t->unsignedInteger('route_id');
                $t->unsignedInteger('vehicle_id');
                $t->dateTime('departure_at');
                $t->dateTime('arrival_at')->nullable();
                $t->string('status', 20)->default('scheduled'); // scheduled | in_progress | completed | cancelled
                $t->text('notes')->nullable();
                $t->unsignedInteger('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['company_id', 'departure_at']);
            });
        }
        if (! Schema::hasTable('transport_trip_crew')) {
            Schema::create('transport_trip_crew', function (Blueprint $t) {
                $t->increments('id');
                $t->unsignedInteger('trip_id')->index();
                $t->unsignedInteger('employee_id')->index();
                $t->string('role', 10); // pilot | hostess
                $t->decimal('trip_rate', 10, 2)->nullable(); // the employee's rate when the trip was planned
            });
        }

        Schema::table('vehicle_maintenances', function (Blueprint $t) {
            if (! Schema::hasColumn('vehicle_maintenances', 'performed_by')) {
                $t->string('performed_by', 12)->default('in_house')->after('vendor'); // in_house | workshop
            }
            if (! Schema::hasColumn('vehicle_maintenances', 'provider_id')) {
                $t->unsignedInteger('provider_id')->nullable()->after('performed_by');
            }
            if (! Schema::hasColumn('vehicle_maintenances', 'mechanic_id')) {
                $t->unsignedInteger('mechanic_id')->nullable()->after('provider_id'); // employee (in house)
            }
        });
        Schema::table('employees', function (Blueprint $t) {
            if (! Schema::hasColumn('employees', 'staff_type')) {
                $t->string('staff_type', 20)->nullable()->after('pay_type'); // administrative | maintenance | pilot | hostess
            }
            if (! Schema::hasColumn('employees', 'trip_rate')) {
                $t->decimal('trip_rate', 10, 2)->nullable()->after('commission_rate');
            }
        });
        Schema::table('payrolls', function (Blueprint $t) {
            if (! Schema::hasColumn('payrolls', 'trips')) {
                $t->unsignedInteger('trips')->nullable()->after('hours');
            }
        });

        foreach (self::PERMS as $name => $label) {
            if (! DB::table('permissions')->where('name', $name)->exists()) {
                DB::table('permissions')->insert(['name' => $name, 'label' => $label, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        // The menu group right after Fleet, wherever each company keeps Fleet.
        foreach (DB::table('menu_positions')->where('item_key', 'fleet')->get(['company_id', 'parent_key', 'sort_order', 'location', 'hidden']) as $ref) {
            if (! DB::table('menu_positions')->where('company_id', $ref->company_id)->where('item_key', 'transport')->exists()) {
                $same = fn ($q) => $ref->parent_key === null ? $q->whereNull('parent_key') : $q->where('parent_key', $ref->parent_key);
                DB::table('menu_positions')->where('company_id', $ref->company_id)->where($same)->where('sort_order', '>', $ref->sort_order)->increment('sort_order');
                DB::table('menu_positions')->insert([
                    'company_id' => $ref->company_id, 'item_key' => 'transport', 'parent_key' => $ref->parent_key, 'sort_order' => $ref->sort_order + 1,
                    'external' => 0, 'is_custom' => 0, 'hidden' => (int) $ref->hidden, 'location' => $ref->location ?: 'sidebar', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach (self::ITEMS as $i => $key) {
                if (! DB::table('menu_positions')->where('company_id', $ref->company_id)->where('item_key', $key)->exists()) {
                    DB::table('menu_positions')->insert([
                        'company_id' => $ref->company_id, 'item_key' => $key, 'parent_key' => 'transport', 'sort_order' => $i,
                        'external' => 0, 'is_custom' => 0, 'hidden' => 0, 'location' => 'sidebar', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }

        foreach (self::TEXTS as $key => [$en, $es]) {
            TranslationKeys::ensure($key, ['es' => $es]);
            foreach (['en' => $en, 'es' => $es] as $col => $text) {
                DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$key])
                    ->where(fn ($q) => $q->whereNull($col)->orWhere($col, '')->orWhere($col, $key))
                    ->update([$col => $text, 'updated_at' => now()]);
            }
        }
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    public function down(): void
    {
    }
};
