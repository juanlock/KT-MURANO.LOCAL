<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Boarding points with their time per trip, the day's price per agency, and a trip's reported figures; then what Turismo
 * Cautivo's own departure reports of 2026-11-04 hold (KT-TOOLS/turismo/reporte): its buses with their seats and that day's
 * 15 departures. Safe to run twice; a company without those names is left alone.
 *
 * A reported trip keeps the report's passengers and amount in reported_passengers / reported_income: no tickets or sales
 * are made up for it. Assumptions, to confirm with the client: the reports don't say where a bus goes, so Lima's are taken
 * as Lima -> Chiclayo and Chiclayo's as Chiclayo -> Lima; P.N. = Plaza Lima Norte, T.Olivos = Los Olivos, CV = Cavassa;
 * 15 deluxe seats on every bus (the user's figure). Other costs of the new buses are unknown and stay empty.
 */
return new class extends Migration
{
    private const LIMA = [
        // seats, bus, boardings, passengers, amount, toll, fuel
        [56, '310', [['PLAZA LIMA NORTE', '17:00'], ['LOS OLIVOS', '17:30']], 32, 2470.00, 658.60, 3254.25],
        [60, '180', [['CAVASSA', '16:30'], ['PLAZA LIMA NORTE', '18:00']], 40, 3150.00, 658.60, 3451.41],
        [56, '230', [['CAVASSA', '17:30'], ['PLAZA LIMA NORTE', '19:00']], 33, 2688.00, 658.60, 3667.60],
        [46, '280', [['PLAZA LIMA NORTE', '19:30'], ['LOS OLIVOS', '20:00']], 19, 1280.00, 658.60, 3505.49],
        [43, '240', [['CAVASSA', '18:30'], ['PLAZA LIMA NORTE', '20:00']], 13, 1540.00, 658.60, 3441.45],
        [44, '380', [['CAVASSA', '19:45'], ['PLAZA LIMA NORTE', '21:00'], ['LOS OLIVOS', '21:30']], 20, 1426.00, 658.60, 3186.01],
        [56, '340', [['CAVASSA', '20:30'], ['PLAZA LIMA NORTE', '22:00']], 22, 1900.00, 658.60, 3137.84],
    ];

    private const CHICLAYO = [
        [46, '270', [['CHICLAYO', '16:00']], 39, 2632.00, null, null],
        [56, '300', [['CHICLAYO', '16:30']], 35, 3152.00, null, null],
        [60, '170', [['CHICLAYO', '17:00'], ['CHEPEN', '18:45'], ['TRUJILLO', '21:30']], 35, 3220.00, null, null],
        [56, '350', [['CHICLAYO', '17:30'], ['CHEPEN', '19:15']], 39, 3390.00, null, null],
        [56, '220', [['CHICLAYO', '18:00'], ['CHEPEN', '19:45']], 38, 3520.00, null, null],
        [44, '370', [['LAMBAYEQYE 2', '18:00'], ['CHICLAYO', '19:00']], 16, 1350.00, null, null],
        [56, '210', [['CHICLAYO', '19:30']], 32, 2900.00, null, null],
        [43, '250', [['LAMBAYEQYE 2', '19:00'], ['CHICLAYO', '20:00']], 31, 3220.00, null, null],
    ];

    private const DATE = '2026-11-04';

    /** How the client's reports write each place. */
    private const LABELS = ['PLAZA LIMA NORTE' => 'P.N.', 'LOS OLIVOS' => 'T.OLIVOS', 'CAVASSA' => 'CV', 'CHICLAYO' => 'CIX', 'CHEPEN' => 'CHEPEN', 'TRUJILLO' => 'TRUX', 'LAMBAYEQYE 2' => 'LAMBAYEQUE'];

    public function up(): void
    {
        if (! Schema::hasColumn('transport_trips', 'reported_passengers')) {
            Schema::table('transport_trips', function (Blueprint $t) {
                $t->unsignedInteger('reported_passengers')->nullable()->after('fuel_cost');
                $t->decimal('reported_income', 12, 2)->nullable()->after('reported_passengers');
            });
        }
        if (! Schema::hasTable('transport_trip_boardings')) {
            Schema::create('transport_trip_boardings', function (Blueprint $t) {
                $t->increments('id');
                $t->integer('company_id')->index();
                $t->unsignedInteger('trip_id')->index();
                $t->unsignedInteger('location_id');
                $t->unsignedSmallInteger('seq')->default(1);
                $t->dateTime('board_at');
                $t->timestamps();
            });
        }
        if (! Schema::hasColumn('transport_trip_boardings', 'label')) {
            Schema::table('transport_trip_boardings', fn (Blueprint $t) => $t->string('label', 40)->nullable()->after('location_id'));
        }
        if (! Schema::hasTable('transport_day_prices')) {
            Schema::create('transport_day_prices', function (Blueprint $t) {
                $t->increments('id');
                $t->integer('company_id')->index();
                $t->unsignedInteger('location_id');
                $t->date('day');
                $t->decimal('price_high', 10, 2)->nullable();
                $t->decimal('price_low', 10, 2)->nullable();
                $t->timestamps();
                $t->unique(['location_id', 'day']);
            });
        }

        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->value('id');
        if (! $cid) {
            return;
        }
        $place = fn (string $name) => DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at')->where('name', $name)->value('id');
        $lima = $place('LIMA');
        $chiclayo = $place('CHICLAYO');
        if (! $lima || ! $chiclayo) {
            return;
        }
        $route = fn ($a, $b) => DB::table('transport_routes')->where('company_id', $cid)->whereNull('deleted_at')->where('origin_id', $a)->where('destination_id', $b)->first();

        DB::transaction(function () use ($cid, $place, $lima, $chiclayo, $route) {
            foreach ([[$lima, $chiclayo, self::LIMA, 120, 70], [$chiclayo, $lima, self::CHICLAYO, 100, 80]] as [$from, $to, $rows, $high, $low]) {
                DB::table('transport_day_prices')->updateOrInsert(
                    ['location_id' => $from, 'day' => self::DATE],
                    ['company_id' => $cid, 'price_high' => $high, 'price_low' => $low, 'updated_at' => now(), 'created_at' => now()]
                );
                $r = $route($from, $to);
                if (! $r) {
                    continue;
                }
                $warehouse = DB::table('transport_locations')->where('id', $from)->value('warehouse_id');
                foreach ($rows as [$seats, $number, $points, $passengers, $amount, $toll, $fuel]) {
                    $this->departure($cid, $place, $r, (int) $warehouse, $seats, $number, $points, $passengers, $amount, $toll, $fuel);
                }
            }
            // The report lists that day's departures: the two demo trips of those agencies are not among them.
            $demo = DB::table('demo_records')->where('company_id', $cid)->where('record_type', 'transport_trips')->pluck('record_id');
            DB::table('transport_trips as t')->join('transport_routes as r', 'r.id', '=', 't.route_id')
                ->where('t.company_id', $cid)->whereIn('t.id', $demo)->whereNull('t.deleted_at')->whereIn('r.origin_id', [$lima, $chiclayo])->whereDate('t.departure_at', self::DATE)
                ->update(['t.deleted_at' => now()]);

            foreach (self::LABELS as $name => $label) {
                DB::table('transport_trip_boardings as b')->join('transport_trips as t', 't.id', '=', 'b.trip_id')
                    ->where('b.company_id', $cid)->where('b.location_id', $place($name))->where('t.notes', 'like', 'Reporte del cliente%')
                    ->update(['b.label' => $label]);
            }
        });
    }

    private function departure(int $cid, callable $place, object $route, int $warehouse, int $seats, string $number, array $points, int $passengers, float $amount, ?float $toll, ?float $fuel): void
    {
        $name = 'Bus '.$number;
        $bus = DB::table('vehicles')->where('company_id', $cid)->whereNull('deleted_at')->where('name', $name)->first();
        $busId = $bus ? (int) $bus->id : (int) DB::table('vehicles')->insertGetId([
            'company_id' => $cid, 'name' => $name, 'plate_number' => '', 'type' => 'bus', 'status' => 'active', 'warehouse_id' => $warehouse, 'fuel_type' => 'diesel',
            'seats' => $seats, 'vip_seats' => 15, 'odometer' => 0, 'notes' => 'Asientos: reporte de salidas del cliente, 04/11/2026.', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($bus) {
            DB::table('vehicles')->where('id', $busId)->update(['seats' => $seats, 'updated_at' => now()]);
        }

        $marker = 'Reporte del cliente '.self::DATE.', '.$name;
        if (DB::table('transport_trips')->where('company_id', $cid)->where('notes', $marker)->exists()) {
            return;
        }
        $departure = self::DATE.' '.$points[0][1].':00';
        $tripId = DB::table('transport_trips')->insertGetId([
            'company_id' => $cid, 'route_id' => $route->id, 'vehicle_id' => $busId, 'departure_at' => $departure,
            'arrival_at' => $route->duration_min ? date('Y-m-d H:i:s', strtotime($departure) + (int) $route->duration_min * 60) : null,
            'status' => 'completed', 'notes' => $marker, 'fuel_cost' => $fuel, 'reported_passengers' => $passengers, 'reported_income' => $amount,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($points as $i => [$point, $time]) {
            $locationId = $place($point);
            if ($locationId) {
                DB::table('transport_trip_boardings')->insert(['company_id' => $cid, 'trip_id' => $tripId, 'location_id' => $locationId, 'seq' => $i + 1, 'board_at' => self::DATE.' '.$time.':00', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        if ($toll !== null) {
            DB::table('transport_tolls')->insert(['company_id' => $cid, 'trip_id' => $tripId, 'place' => 'Reporte del cliente', 'amount' => $toll, 'paid_on' => self::DATE, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Left on purpose: real figures of a company in production.
    }
};
