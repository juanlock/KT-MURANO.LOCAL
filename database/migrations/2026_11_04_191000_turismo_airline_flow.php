<?php

use App\Support\TransportDemoDay;
use App\Support\TransportTickets;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Turismo Cautivo as the user described it (2026-11-04). Real data: the stops on its long routes (the Lima -> Chepén
 * bus reaches Trujillo and leaves again 25 minutes later, selling the seats emptied), 44 seats with 11 on the first
 * floor, 15 VIP at 20 % more, every 10th trip free, supervisors at S/ 2,000 a month.
 * Demo rows only: units 380 and 370 with their photos and plates, trips on long routes only,
 * passengers with seats, parcels of about S/ 23,000 a month, crew on the payroll (70 % of pilots, 85 % of
 * hostesses), one mechanic, spare parts and the maintenance plan. Safe to run twice.
 */
return new class extends Migration
{
    private const STOPS = [
        ['LIMA', 'CHICLAYO', [['TRUJILLO', 557.4, 540], ['CHEPEN', 698.2, 685]]],
        ['LIMA', 'CHEPEN', [['TRUJILLO', 557.4, 540]]],
        ['CHICLAYO', 'LIMA', [['CHEPEN', 78, 75], ['TRUJILLO', 218, 215]]],
        ['CHEPEN', 'LIMA', [['TRUJILLO', 140, 120]]],
    ];

    /** The routes a bus really runs; the short stretches are stops on them. */
    private const LONG = [['LIMA', 'CHICLAYO'], ['CHICLAYO', 'LIMA'], ['LIMA', 'CHEPEN'], ['CHEPEN', 'LIMA'], ['LIMA', 'TRUJILLO'], ['TRUJILLO', 'LIMA']];

    private const SUPERVISOR_SALARY = 2000; // the user's figure, 2026-11-04

    private const MECHANIC_SALARY = 2000;

    public function up(): void
    {
        $cid = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if (! $cid) {
            return;
        }
        $demo = fn (string $type) => DB::table('demo_records')->where('company_id', $cid)->where('record_type', $type)->pluck('record_id')->all();
        $ids = DB::table('transport_locations')->where('company_id', $cid)->whereNull('deleted_at')->where('kind', 'agency')->pluck('id', 'name');
        $routeId = fn ($o, $d) => isset($ids[$o], $ids[$d]) ? (int) DB::table('transport_routes')->where('company_id', $cid)->where('origin_id', $ids[$o])->where('destination_id', $ids[$d])->whereNull('deleted_at')->value('id') : 0;

        DB::transaction(function () use ($cid, $ids, $routeId, $demo) {
            // Real data.
            foreach (self::STOPS as [$o, $d, $stops]) {
                $route = $routeId($o, $d);
                if (! $route || DB::table('transport_route_stops')->where('route_id', $route)->exists()) {
                    continue;
                }
                foreach ($stops as $i => [$name, $km, $minutes]) {
                    DB::table('transport_route_stops')->insert(['company_id' => $cid, 'route_id' => $route, 'location_id' => $ids[$name], 'seq' => $i + 1, 'km' => $km, 'minutes' => $minutes, 'dwell_min' => 25, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            DB::table('vehicles')->where('company_id', $cid)->whereNull('deleted_at')->where('type', 'bus')->whereNull('seats_lower')->update(['seats' => 44, 'seats_lower' => 11, 'vip_seats' => 15]);
            DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->update(['transport_loyalty_every' => 10]);
            TransportTickets::ensureVipProducts($cid);
            foreach (DB::table('users')->where('company_id', $cid)->where('email', 'like', '%-super-%@kcoltech.com')->pluck('id') as $uid) {
                DB::table('employees')->where('user_id', $uid)->update(['basic_salary' => self::SUPERVISOR_SALARY, 'staff_type' => 'administrative', 'pay_type' => 'monthly', 'updated_at' => now()]);
            }

            // Demo buses: the two real units, the same model on all.
            $buses = DB::table('vehicles')->whereIn('id', $demo('vehicles'))->whereNull('deleted_at')->orderBy('id')->pluck('id')->all();
            DB::table('vehicles')->whereIn('id', $buses)->update(['make' => 'Scania', 'model' => 'Marcopolo Paradiso 1600 G8', 'notes' => 'Demo data. 44 asientos: 11 sofá cama (160°) abajo y 33 semicama (145°) arriba; 15 VIP.']);
            foreach ([[0, 'Bus 380', 'DBC-486', 'bus-380.jpg'], [1, 'Bus 370', 'DBG-276', 'bus-370.jpg']] as [$i, $name, $plate, $photo]) {
                if (! isset($buses[$i])) {
                    continue;
                }
                $set = ['name' => $name, 'plate_number' => $plate];
                $file = database_path('assets/turismo/'.$photo);
                if (is_file($file)) {
                    $set['image'] = \App\Support\CompanyFiles::putBytes($cid, 'vehicles', $photo, file_get_contents($file));
                }
                DB::table('vehicles')->where('id', $buses[$i])->update($set);
            }

            // Demo trips: buses run long routes; the short stretches are sold as stops on them.
            $long = array_values(array_filter(array_map(fn ($p) => $routeId($p[0], $p[1]), self::LONG)));
            $durations = DB::table('transport_routes')->whereIn('id', $long)->pluck('duration_min', 'id');
            $trips = DB::table('transport_trips')->whereIn('id', $demo('transport_trips'))->orderBy('departure_at')->get(['id', 'route_id', 'departure_at']);
            foreach ($trips as $n => $t) {
                $route = in_array((int) $t->route_id, $long, true) ? (int) $t->route_id : $long[$n % count($long)];
                DB::table('transport_trips')->where('id', $t->id)->update(['route_id' => $route, 'arrival_at' => \Carbon\Carbon::parse($t->departure_at)->addMinutes((int) $durations[$route]), 'fuel_cost' => null, 'updated_at' => now()]);
            }
            // Their passengers and parcels again, with seats and stops.
            $tickets = $demo('transport_tickets');
            DB::table('transport_tickets')->whereIn('id', $tickets)->delete();
            DB::table('demo_records')->where('company_id', $cid)->where('record_type', 'transport_tickets')->delete();
            $parcels = $demo('transport_parcels');
            DB::table('transport_parcels')->whereIn('id', $parcels)->delete();
            DB::table('demo_records')->where('company_id', $cid)->where('record_type', 'transport_parcels')->delete();

            // Demo crew: 70 % of the pilots and 85 % of the hostesses on the payroll; the rest called when demand is high.
            foreach (['pilot' => [0.7, 2060], 'hostess' => [0.85, 1210]] as $type => [$share, $salary]) {
                $crew = DB::table('employees')->whereIn('id', $demo('employees'))->whereNull('deleted_at')->where('staff_type', $type)->orderBy('id')->pluck('id');
                DB::table('employees')->whereIn('id', $crew)->update(['trip_rate' => 50, 'basic_salary' => 0, 'pay_type' => 'per_trip', 'updated_at' => now()]);
                DB::table('employees')->whereIn('id', $crew->take((int) round($crew->count() * $share)))->update(['basic_salary' => $salary, 'pay_type' => 'monthly', 'updated_at' => now()]);
            }
            DB::table('employees')->whereIn('id', $demo('employees'))->where('basic_salary', 1300)->update(['pay_type' => 'monthly', 'updated_at' => now()]);

            // One mechanic, in Lima.
            $mechanics = DB::table('employees')->whereIn('id', $demo('employees'))->whereNull('deleted_at')->where('staff_type', 'maintenance')->orderBy('id')->pluck('id');
            if ($mechanics->isNotEmpty()) {
                DB::table('employees')->where('id', $mechanics->first())->update(['basic_salary' => self::MECHANIC_SALARY, 'pay_type' => 'monthly', 'updated_at' => now()]);
                DB::table('vehicle_maintenances')->whereIn('mechanic_id', $mechanics->slice(1))->update(['mechanic_id' => $mechanics->first()]);
                foreach (DB::table('employees')->whereIn('id', $mechanics->slice(1))->get(['id', 'user_id']) as $extra) {
                    DB::table('employees')->where('id', $extra->id)->update(['deleted_at' => now(), 'leaving_date' => now()->toDateString()]);
                    if ($extra->user_id) {
                        DB::table('users')->where('id', $extra->user_id)->update(['statut' => 0, 'updated_at' => now()]);
                    }
                }
            }

            $this->workshop($cid, $buses);
        });

        \App\Support\TransportLegs::forget();
        echo '  Turismo demo: '.json_encode(TransportDemoDay::profitData($cid, true))."\n";
    }

    /** The demo's spare parts and maintenance plan. */
    private function workshop(int $cid, array $buses): void
    {
        $tag = fn (string $type, int $id) => DB::table('demo_records')->insert(['company_id' => $cid, 'module' => 'transport', 'record_type' => $type, 'record_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        if (! DB::table('vehicle_parts')->where('company_id', $cid)->exists()) {
            foreach ([['ACE-15W40', 'Aceite de motor 15W-40', 'gal', 60, 20, 95], ['FIL-ACE', 'Filtro de aceite', 'und', 12, 4, 120], ['FIL-AIR', 'Filtro de aire', 'und', 6, 2, 280], ['FIL-DIE', 'Filtro de diésel', 'und', 10, 4, 150],
                ['LLA-295', 'Llanta 295/80 R22.5', 'und', 8, 6, 1450], ['PAS-FRE', 'Pastillas de freno (juego)', 'jgo', 4, 2, 780], ['REF-MOT', 'Refrigerante', 'gal', 20, 8, 45], ['COR-ALT', 'Correa de alternador', 'und', 3, 2, 210]] as [$code, $name, $unit, $stock, $min, $cost]) {
                $tag('vehicle_parts', DB::table('vehicle_parts')->insertGetId(['company_id' => $cid, 'code' => $code, 'name' => $name, 'unit' => $unit, 'stock' => $stock, 'min_stock' => $min, 'unit_cost' => $cost, 'created_at' => now(), 'updated_at' => now()]));
            }
        }
        if (! DB::table('vehicle_maintenance_plans')->where('company_id', $cid)->exists()) {
            foreach ($buses as $i => $bus) {
                $odometer = (float) DB::table('vehicles')->where('id', $bus)->value('odometer');
                foreach ([['Cambio de aceite y filtros', 'service', 15000, null, 11000 + $i * 2200], ['Filtros de aire y diésel', 'service', 30000, null, 12000 + $i * 6000], ['Pastillas de freno', 'repair', 40000, null, 18000 + $i * 7000],
                    ['Rotación y revisión de llantas', 'tyres', 20000, null, 6000 + $i * 5000], ['Revisión técnica', 'inspection', null, 180, 0]] as [$title, $type, $km, $days, $since]) {
                    $tag('vehicle_maintenance_plans', DB::table('vehicle_maintenance_plans')->insertGetId(['company_id' => $cid, 'vehicle_id' => $bus, 'title' => $title, 'type' => $type, 'every_km' => $km, 'every_days' => $days,
                        'last_km' => $km ? $odometer - $since : null, 'last_date' => now()->subDays(150 + $i * 12)->toDateString(), 'active' => 1, 'created_at' => now(), 'updated_at' => now()]));
                }
            }
        }
    }

    public function down(): void
    {
    }
};
