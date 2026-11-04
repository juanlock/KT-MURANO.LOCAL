<?php

use App\Support\TransportAgencies;
use App\Support\TransportDemoDay;
use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Is each trip worth it? (Turismo Cautivo's owner, 2026-11-04.) What a trip's profit
 * needs: seats and cargo limits on the buses, depreciation data, a ticket's price, the
 * trip's real fuel, tolls (per trip, with an expected cost on the route), parcels with
 * their box measures, and the agency supervisor's decisions (run, cancel, reschedule).
 * Permissions, menu items, the Supervisor role with a login per agency, and the demo's
 * figures. Safe to run twice.
 */
return new class extends Migration
{
    private const PERMS = [
        'transport_supervise' => 'Transport: decide today\'s departures of the agency',
        'transport_parcels' => 'Transport: register parcels',
        'transport_tolls' => 'Transport: register tolls',
        'transport_profit' => 'Transport: see each trip\'s profit',
    ];

    private const ITEMS = ['/app/transport/today', '/app/transport/parcels', '/app/transport/tolls'];

    private const TEXTS = [
        'Transport_Today' => ['Today\'s departures', 'Salidas de hoy'],
        'Transport_Parcels' => ['Parcels', 'Encomiendas'],
        'Transport_Tolls' => ['Tolls', 'Peajes'],
        'Vehicle_Seats' => ['Seats', 'Asientos'],
        'Vehicle_Cargo_Volume' => ['Cargo hold (m³)', 'Bodega (m³)'],
        'Vehicle_Cargo_Weight' => ['Cargo limit (kg)', 'Límite de carga (kg)'],
        'Vehicle_Useful_Life' => ['Useful life (km)', 'Vida útil (km)'],
        'Vehicle_Residual_Value' => ['Residual value', 'Valor residual'],
        'transport_supervise' => ['Transport: decide today\'s departures of the agency', 'Transporte: decidir las salidas de hoy de la agencia'],
        'transport_parcels' => ['Transport: register parcels', 'Transporte: registrar encomiendas'],
        'transport_tolls' => ['Transport: register tolls', 'Transporte: registrar peajes'],
        'transport_profit' => ['Transport: see each trip\'s profit', 'Transporte: ver la ganancia de cada viaje'],
    ];

    public function up(): void
    {
        $this->schema();

        foreach (self::PERMS as $name => $label) {
            if (! DB::table('permissions')->where('name', $name)->exists()) {
                DB::table('permissions')->insert(['name' => $name, 'label' => $label, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        // IT Admin and Manager have them; the module switch decides who sees them.
        $permIds = DB::table('permissions')->whereIn('name', array_keys(self::PERMS))->pluck('id');
        foreach (DB::table('roles')->whereIn('name', ['IT Admin', 'Manager'])->whereNull('deleted_at')->pluck('id') as $rid) {
            foreach ($permIds as $pid) {
                if (! DB::table('permission_role')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $rid, 'permission_id' => $pid]);
                }
            }
        }

        $this->menu();

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

        // The Supervisor role and its logins; the ticket offices can register parcels.
        foreach (DB::table('transport_locations')->where('kind', 'agency')->whereNull('deleted_at')->distinct()->pluck('company_id') as $cid) {
            if (DB::table('companies')->where('id', $cid)->whereNull('deleted_at')->exists()) {
                DB::transaction(fn () => TransportAgencies::sync((int) $cid));
                echo "  company {$cid}: supervisors\n";
            }
        }

        // Turismo Cautivo's buses have 44 seats (user's data, 2026-11-04: Scania / Marcopolo Paradiso
        // G8 of 15 m, 11 sofá cama + 33 semicama); only when no one has filled them in.
        $turismo = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if ($turismo) {
            DB::table('vehicles')->where('company_id', $turismo)->whereNull('deleted_at')->whereNull('seats')->where('type', 'bus')->update(['seats' => 44]);
            // Its demo buses look like its real fleet: the plates and prices are still the demo's.
            DB::table('vehicles')->where('company_id', $turismo)->whereNull('deleted_at')->where('notes', 'Demo data')->where('type', 'bus')
                ->update(['make' => 'Scania', 'model' => 'Marcopolo Paradiso G8 (15 m)', 'notes' => 'Demo data. 44 asientos: 11 sofá cama (160°) y 33 semicama (145°).']);
        }

        // The demos' figures (only rows tagged as demo).
        foreach (TransportDemoDay::companies() as $cid) {
            echo "  company {$cid}: demo ".json_encode(TransportDemoDay::profitData((int) $cid, true))."\n";
        }
    }

    private function schema(): void
    {
        Schema::table('vehicles', function (Blueprint $t) {
            if (! Schema::hasColumn('vehicles', 'seats')) {
                $t->unsignedSmallInteger('seats')->nullable()->after('tank_capacity');
            }
            if (! Schema::hasColumn('vehicles', 'cargo_volume_m3')) {
                $t->decimal('cargo_volume_m3', 8, 2)->nullable()->after('seats');
            }
            if (! Schema::hasColumn('vehicles', 'cargo_weight_kg')) {
                $t->decimal('cargo_weight_kg', 10, 2)->nullable()->after('cargo_volume_m3');
            }
            if (! Schema::hasColumn('vehicles', 'useful_life_km')) {
                $t->unsignedInteger('useful_life_km')->nullable()->after('purchase_price');
            }
            if (! Schema::hasColumn('vehicles', 'residual_value')) {
                $t->decimal('residual_value', 12, 2)->nullable()->after('useful_life_km');
            }
        });
        Schema::table('transport_routes', function (Blueprint $t) {
            if (! Schema::hasColumn('transport_routes', 'toll_cost')) {
                $t->decimal('toll_cost', 10, 2)->nullable()->after('duration_min'); // expected, until the trip has its own
            }
        });
        Schema::table('transport_trips', function (Blueprint $t) {
            if (! Schema::hasColumn('transport_trips', 'fuel_cost')) {
                $t->decimal('fuel_cost', 10, 2)->nullable()->after('notes'); // real; empty = the bus's cost per km
            }
            if (! Schema::hasColumn('transport_trips', 'confirmed_at')) {
                $t->dateTime('confirmed_at')->nullable()->after('fuel_cost');
                $t->unsignedInteger('confirmed_by')->nullable()->after('confirmed_at');
            }
        });
        Schema::table('transport_tickets', function (Blueprint $t) {
            if (! Schema::hasColumn('transport_tickets', 'price')) {
                $t->decimal('price', 10, 2)->nullable()->after('passenger_type');
            }
        });
        if (Schema::hasColumn('transport_tickets', 'price')) {
            // What each existing ticket was sold for.
            DB::statement('UPDATE transport_tickets t JOIN sale_details d ON d.sale_id = t.sale_id AND d.product_id = t.product_id
                SET t.price = ROUND(d.total / NULLIF(d.quantity, 0), 2) WHERE t.price IS NULL');
        }
        if (! Schema::hasTable('transport_trip_decisions')) {
            Schema::create('transport_trip_decisions', function (Blueprint $t) {
                $t->increments('id');
                $t->integer('company_id')->index();
                $t->unsignedInteger('trip_id')->index();
                $t->string('action', 12); // confirm | cancel | reschedule
                $t->string('note', 500)->nullable();
                $t->dateTime('old_departure_at')->nullable();
                $t->dateTime('new_departure_at')->nullable();
                $t->unsignedInteger('user_id')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('transport_tolls')) {
            Schema::create('transport_tolls', function (Blueprint $t) {
                $t->increments('id');
                $t->integer('company_id')->index();
                $t->unsignedInteger('trip_id')->index();
                $t->string('place', 120);
                $t->decimal('amount', 10, 2);
                $t->date('paid_on');
                $t->string('note', 255)->nullable();
                $t->unsignedInteger('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('transport_parcels')) {
            Schema::create('transport_parcels', function (Blueprint $t) {
                $t->increments('id');
                $t->integer('company_id')->index();
                $t->string('number', 20)->index();
                $t->unsignedInteger('trip_id')->index();
                $t->unsignedInteger('warehouse_id')->nullable(); // the agency that received it
                $t->string('sender_name', 120);
                $t->string('sender_phone', 40)->nullable();
                $t->string('receiver_name', 120);
                $t->string('receiver_phone', 40)->nullable();
                $t->string('description', 190)->nullable();
                $t->unsignedSmallInteger('pieces')->default(1);
                $t->decimal('length_cm', 7, 1);
                $t->decimal('width_cm', 7, 1);
                $t->decimal('height_cm', 7, 1);
                $t->decimal('weight_kg', 9, 2)->default(0); // the whole parcel
                $t->decimal('volume_m3', 9, 4); // length x width x height x pieces
                $t->decimal('price', 10, 2)->default(0);
                $t->string('status', 12)->default('received'); // received | on_board | delivered | cancelled
                $t->string('delivered_to', 120)->nullable();
                $t->dateTime('delivered_at')->nullable();
                $t->unsignedInteger('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
    }

    private function menu(): void
    {
        $now = now();
        foreach (DB::table('menu_positions')->where('item_key', 'transport')->pluck('company_id') as $cid) {
            foreach (self::ITEMS as $i => $key) {
                if (! DB::table('menu_positions')->where('company_id', $cid)->where('item_key', $key)->exists()) {
                    DB::table('menu_positions')->insert([
                        'company_id' => $cid, 'item_key' => $key, 'parent_key' => 'transport', 'sort_order' => 10 + $i,
                        'external' => 0, 'is_custom' => 0, 'hidden' => 0, 'location' => 'sidebar', 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }
        // Turismo Cautivo's six groups: each one in its place.
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if (! $cid || ! DB::table('menu_positions')->where('company_id', $cid)->where('item_key', 'custom_tc_flota')->exists()) {
            return;
        }
        $rows = fn () => DB::table('menu_positions')->where('company_id', $cid);
        $rows()->where('parent_key', 'custom_tc_flota')->increment('sort_order', 2);
        $rows()->where('item_key', '/app/transport/today')->update(['parent_key' => 'custom_tc_flota', 'sort_order' => 0, 'updated_at' => $now]);
        $rows()->where('item_key', '/app/transport/tolls')->update(['parent_key' => 'custom_tc_flota', 'sort_order' => 2, 'updated_at' => $now]);
        $rows()->where('parent_key', 'custom_tc_encomiendas')->increment('sort_order');
        $rows()->where('item_key', '/app/transport/parcels')->update(['parent_key' => 'custom_tc_encomiendas', 'sort_order' => 0, 'updated_at' => $now]);
    }

    public function down(): void
    {
    }
};
