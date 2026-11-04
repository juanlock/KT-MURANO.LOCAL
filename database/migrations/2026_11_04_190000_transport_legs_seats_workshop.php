<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Transport works like an airline (user's rule, 2026-11-04): one trip with stops, a seat chosen per
 * stretch (a seat emptied at a stop is sold again from there), VIP seats, a boarding pass with a QR;
 * parcels with their cargo type, sale, guide and public tracking; the workshop: spare parts,
 * parts used on each job and the maintenance plan. Safe to run twice.
 */
return new class extends Migration
{
    private const PERMS = [
        'transport_boarding' => 'Transport: board passengers',
        'fleet_parts' => 'Fleet: spare parts',
        'fleet_plan' => 'Fleet: maintenance plan',
    ];

    private const ITEMS = [
        'transport' => ['/app/transport/boarding'],
        'fleet' => ['/app/fleet/parts', '/app/fleet/plan'],
    ];

    private const TEXTS = [
        'Transport_Boarding' => ['Boarding', 'Abordaje'],
        'Fleet_Parts' => ['Spare parts', 'Repuestos'],
        'Transport_Seat' => ['Seat', 'Asiento'],
        'Transport_Floor' => ['Floor', 'Piso'],
        'Transport_Loyalty_Free' => ['This trip is free: trips paid', 'Este viaje es gratis: viajes pagados'],
        'Transport_Loyalty_Discount' => ['Apply the discount on the line.', 'Aplique el descuento en la línea.'],
        'Vehicle_Seats_Lower' => ['Seats on the first floor', 'Asientos del primer piso'],
        'Vehicle_Vip_Seats' => ['VIP seats (the first ones)', 'Asientos VIP (los primeros)'],
        'Fleet_Plan' => ['Maintenance plan', 'Plan de mantenimiento'],
        'transport_boarding' => ['Transport: board passengers', 'Transporte: abordar pasajeros'],
        'fleet_parts' => ['Fleet: spare parts', 'Flota: repuestos'],
        'fleet_plan' => ['Fleet: maintenance plan', 'Flota: plan de mantenimiento'],
    ];

    public function up(): void
    {
        $this->schema();

        foreach (self::PERMS as $name => $label) {
            if (! DB::table('permissions')->where('name', $name)->exists()) {
                DB::table('permissions')->insert(['name' => $name, 'label' => $label, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $grant = function (array $roles, array $perms) {
            $permIds = DB::table('permissions')->whereIn('name', $perms)->pluck('id');
            foreach (DB::table('roles')->whereIn('name', $roles)->whereNull('deleted_at')->pluck('id') as $rid) {
                foreach ($permIds as $pid) {
                    if (! DB::table('permission_role')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
                        DB::table('permission_role')->insert(['role_id' => $rid, 'permission_id' => $pid]);
                    }
                }
            }
        };
        $grant(['IT Admin', 'Manager'], array_keys(self::PERMS));
        $grant(['Mecánico'], ['fleet_parts', 'fleet_plan']);
        $grant(['Supervisor', 'Tripulación'], ['transport_boarding']);

        $now = now();
        foreach (self::ITEMS as $parent => $keys) {
            foreach (DB::table('menu_positions')->where('item_key', $parent)->pluck('company_id') as $cid) {
                foreach ($keys as $i => $key) {
                    if (! DB::table('menu_positions')->where('company_id', $cid)->where('item_key', $key)->exists()) {
                        DB::table('menu_positions')->insert(['company_id' => $cid, 'item_key' => $key, 'parent_key' => $parent, 'sort_order' => 20 + $i,
                            'external' => 0, 'is_custom' => 0, 'hidden' => 0, 'location' => 'sidebar', 'created_at' => $now, 'updated_at' => $now]);
                    }
                }
            }
        }
        // Turismo Cautivo's six groups.
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if ($cid && DB::table('menu_positions')->where('company_id', $cid)->where('item_key', 'custom_tc_pasajeros')->exists()) {
            $rows = fn () => DB::table('menu_positions')->where('company_id', $cid);
            $rows()->where('item_key', '/app/transport/boarding')->update(['parent_key' => 'custom_tc_pasajeros', 'sort_order' => 1, 'updated_at' => $now]);
            $rows()->where('parent_key', 'custom_tc_pasajeros')->where('item_key', '!=', '/app/transport/boarding')->where('sort_order', '>=', 1)->increment('sort_order');
            foreach (['/app/fleet/plan' => 7, '/app/fleet/parts' => 8] as $key => $sort) {
                $rows()->where('item_key', $key)->update(['parent_key' => 'custom_tc_flota', 'sort_order' => $sort + 2, 'updated_at' => $now]);
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

    private function schema(): void
    {
        Schema::table('vehicles', function (Blueprint $t) {
            if (! Schema::hasColumn('vehicles', 'seats_lower')) {
                $t->unsignedSmallInteger('seats_lower')->nullable()->after('seats'); // seats on the first floor, numbered first
            }
            if (! Schema::hasColumn('vehicles', 'vip_seats')) {
                $t->unsignedSmallInteger('vip_seats')->nullable()->after('seats_lower'); // the first N seats are VIP
            }
        });
        Schema::table('transport_tickets', function (Blueprint $t) {
            if (! Schema::hasColumn('transport_tickets', 'seat_number')) {
                $t->unsignedSmallInteger('seat_number')->nullable()->after('trip_id');
                $t->boolean('is_vip')->default(false)->after('seat_number');
                $t->unsignedInteger('from_location_id')->nullable()->after('is_vip');
                $t->unsignedInteger('to_location_id')->nullable()->after('from_location_id');
                $t->string('code', 14)->nullable()->index()->after('to_location_id');
                $t->dateTime('boarded_at')->nullable()->after('code');
                $t->unsignedInteger('boarded_by')->nullable()->after('boarded_at');
                $t->boolean('loyalty_free')->default(false)->after('boarded_by');
            }
        });
        if (! Schema::hasTable('transport_route_stops')) {
            Schema::create('transport_route_stops', function (Blueprint $t) {
                $t->increments('id');
                $t->integer('company_id')->index();
                $t->unsignedInteger('route_id')->index();
                $t->unsignedInteger('location_id');
                $t->unsignedSmallInteger('seq');
                $t->decimal('km', 8, 1); // from the route's origin
                $t->unsignedInteger('minutes'); // arrival, from the route's departure
                $t->unsignedSmallInteger('dwell_min')->default(25);
                $t->timestamps();
            });
        }
        Schema::table('transport_parcels', function (Blueprint $t) {
            if (! Schema::hasColumn('transport_parcels', 'cargo_type')) {
                $t->string('cargo_type', 12)->default('general')->after('description'); // general | fragile
                $t->unsignedInteger('from_location_id')->nullable()->after('warehouse_id');
                $t->unsignedInteger('to_location_id')->nullable()->after('from_location_id');
                $t->unsignedInteger('sale_id')->nullable()->index()->after('price');
                $t->string('payment', 10)->default('paid')->after('sale_id'); // paid | collect (the receiver pays)
                $t->string('tracking_token', 32)->nullable()->index()->after('payment');
            }
        });
        // The system picks a parcel's bus: it can wait without a trip, with its date and estimate (the next migrations' demo needs them).
        DB::statement('ALTER TABLE transport_parcels MODIFY trip_id INT UNSIGNED NULL');
        Schema::table('transport_parcels', function (Blueprint $t) {
            if (! Schema::hasColumn('transport_parcels', 'received_on')) {
                $t->date('received_on')->nullable()->after('warehouse_id');
                $t->date('eta_from')->nullable()->after('received_on');
                $t->date('eta_to')->nullable()->after('eta_from');
            }
        });
        if (! Schema::hasTable('transport_parcel_events')) {
            Schema::create('transport_parcel_events', function (Blueprint $t) {
                $t->increments('id');
                $t->unsignedInteger('parcel_id')->index();
                $t->string('status', 12);
                $t->unsignedInteger('user_id')->nullable();
                $t->dateTime('created_at');
            });
        }
        if (! Schema::hasColumn('settings', 'transport_loyalty_every')) {
            Schema::table('settings', function (Blueprint $t) {
                $t->unsignedSmallInteger('transport_loyalty_every')->nullable(); // every Nth trip is free
            });
        }
        if (! Schema::hasTable('vehicle_parts')) {
            Schema::create('vehicle_parts', function (Blueprint $t) {
                $t->increments('id');
                $t->integer('company_id')->index();
                $t->string('code', 40)->nullable();
                $t->string('name', 150);
                $t->string('unit', 20)->default('und');
                $t->decimal('stock', 10, 2)->default(0);
                $t->decimal('min_stock', 10, 2)->default(0);
                $t->decimal('unit_cost', 10, 2)->default(0);
                $t->unsignedInteger('provider_id')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('vehicle_part_moves')) {
            Schema::create('vehicle_part_moves', function (Blueprint $t) {
                $t->increments('id');
                $t->unsignedInteger('part_id')->index();
                $t->string('kind', 10); // in | used | adjust
                $t->decimal('quantity', 10, 2); // + in, - out
                $t->decimal('unit_cost', 10, 2)->default(0);
                $t->unsignedInteger('maintenance_id')->nullable()->index();
                $t->string('note', 190)->nullable();
                $t->unsignedInteger('user_id')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('vehicle_maintenance_plans')) {
            Schema::create('vehicle_maintenance_plans', function (Blueprint $t) {
                $t->increments('id');
                $t->integer('company_id')->index();
                $t->unsignedInteger('vehicle_id')->index();
                $t->string('title', 150);
                $t->string('type', 20)->default('service');
                $t->unsignedInteger('every_km')->nullable();
                $t->unsignedSmallInteger('every_days')->nullable();
                $t->decimal('last_km', 12, 2)->nullable();
                $t->date('last_date')->nullable();
                $t->boolean('active')->default(true);
                $t->timestamps();
                $t->softDeletes();
            });
        }
        Schema::table('vehicle_maintenances', function (Blueprint $t) {
            if (! Schema::hasColumn('vehicle_maintenances', 'plan_id')) {
                $t->unsignedInteger('plan_id')->nullable()->after('mechanic_id');
                $t->decimal('labor_cost', 10, 2)->nullable()->after('cost');
            }
        });
    }

    public function down(): void
    {
    }
};
