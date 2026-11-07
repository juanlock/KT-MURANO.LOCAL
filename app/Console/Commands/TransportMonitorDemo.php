<?php

namespace App\Console\Commands;

use App\Services\TransportControl;
use App\Support\CompanyFiles;
use App\Support\TransportLegs;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Emulated data for the control board (user's request, 2026-11-06: "por ahora emulemos data", "llena con mas datos"). DEVELOPER
 * PC ONLY: it refuses any database but this PC's dbmurano, and a Wake-up (production's copy) wipes what it made, so run it again after one.
 *   php artisan transport:monitor-demo          builds it (again)
 *   php artisan transport:monitor-demo --loop   and keeps the buses moving, every 45 s, until stopped
 *   php artisan transport:monitor-demo --clear  removes it
 * Its trips carry the note MONITOR_DEMO. Real people of the company are the crew, so each login sees its own trip.
 */
class TransportMonitorDemo extends Command
{
    private const NOTE = 'MONITOR_DEMO';

    /** On the road now: route, minutes since it should have left, minutes late, GPS (live | none | lost | stopped), incident. */
    private const ON_ROAD = [
        [1, 200, 35, 'live', null],
        [3, 330, 0, 'live', null],
        [4, 150, 55, 'stopped', ['breakdown', 'Falla de frenos, esperando mecánico.']],
        [10, 90, 10, 'none', null],
        [7, 100, 5, 'lost', null],
        [2, 420, 15, 'live', ['traffic', 'Tráfico lento por obras en la Panamericana.']],
        [1, 610, 22, 'live', ['road', 'Paso a un carril a la altura de Chimbote.']],
        [11, 60, 3, 'live', null],
        [12, 45, 0, 'live', null],
        [8, 30, 12, 'live', null],
        [4, 480, 40, 'live', ['passenger', 'Pasajero con malestar, atendido en la terminal.']],
        [10, 300, 18, 'live', null],
    ];

    /** Left and arrived today: route, minutes since it should have left, minutes late leaving and arriving, last photo taken. */
    private const ARRIVED = [
        [5, 300, 8, 25, true], [9, 400, 0, 2, true], [12, 520, 15, 12, true], [8, 240, 5, 30, false], [6, 610, 3, 6, true],
    ];

    /** Later today: route, minutes from now. */
    private const SCHEDULED = [[6, 90], [3, 150], [10, 200], [4, 260], [2, 320]];

    /** Each pilot's habit in the last two weeks, for Punctuality: minutes late leaving and arriving. */
    private const HABIT = [[3, 6], [18, 25], [0, 2], [30, 12], [8, 9], [5, 40]];

    private const FIRST_NAMES = ['Ana', 'Carlos', 'Rosa', 'Luis', 'Carmen', 'Jorge', 'Elena', 'Pedro', 'Julia', 'Víctor', 'Sofía', 'Raúl', 'Patricia', 'Hugo', 'Lucía', 'Marco'];

    private const LAST_NAMES = ['Quispe', 'Flores', 'Sánchez', 'Rojas', 'Díaz', 'Torres', 'Vargas', 'Castillo', 'Mendoza', 'Ramos', 'Chávez', 'Paredes', 'Huamán', 'Salazar'];

    private const CARGO = ['Ropa', 'Repuestos', 'Documentos', 'Abarrotes', 'Televisor', 'Calzado', 'Medicinas', 'Artesanías'];

    protected $signature = 'transport:monitor-demo {--clear} {--loop}';

    protected $description = 'Developer PC: emulated buses for the transport control board';

    private int $company;

    private array $photos = [];

    public function handle(): int
    {
        if (config('database.connections.mysql.database') !== 'dbmurano') {
            $this->error('Only on a developer PC (database dbmurano). Nothing done.');

            return self::FAILURE;
        }
        $this->company = (int) DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id');
        if (! $this->company) {
            $this->error('Turismo Cautivo not found.');

            return self::FAILURE;
        }
        $this->clear();
        if ($this->option('clear')) {
            $this->info('Emulated data removed.');

            return self::SUCCESS;
        }
        $this->build();
        if ($this->option('loop')) {
            $this->info('Moving the buses every 45 s. Ctrl+C to stop.');
            while (true) {
                sleep(45);
                DB::reconnect();
                $this->move();
            }
        }

        return self::SUCCESS;
    }

    private function clear(): void
    {
        $ids = DB::table('transport_trips')->where('company_id', $this->company)->where('notes', self::NOTE)->pluck('id');
        $photos = DB::table('transport_trip_checkpoints')->whereIn('trip_id', $ids)->whereNotNull('photo')->distinct()->pluck('photo')
            ->merge(DB::table('transport_trip_reports')->whereIn('trip_id', $ids)->whereNotNull('photo')->distinct()->pluck('photo'))->unique();
        foreach ($photos as $p) {
            if (CompanyFiles::owns($p, $this->company, 'transport')) {
                @unlink(public_path($p));
            }
        }
        foreach (['transport_trip_checkpoints', 'transport_trip_reports', 'transport_vehicle_positions', 'transport_trip_crew', 'transport_trip_boardings', 'transport_tickets'] as $table) {
            DB::table($table)->whereIn('trip_id', $ids)->delete();
        }
        $parcels = DB::table('transport_parcels')->whereIn('trip_id', $ids)->pluck('id');
        DB::table('transport_parcel_events')->whereIn('parcel_id', $parcels)->delete();
        DB::table('transport_parcels')->whereIn('id', $parcels)->delete();
        DB::table('transport_trips')->whereIn('id', $ids)->delete();
    }

    /** The logins of a role, with their employee. */
    private function people(string $role)
    {
        return DB::table('users as u')->join('role_user as ru', 'ru.user_id', '=', 'u.id')->join('roles as r', 'r.id', '=', 'ru.role_id')->join('employees as e', 'e.user_id', '=', 'u.id')
            ->where('r.company_id', $this->company)->where('r.name', $role)->where('u.statut', 1)->whereNull('e.deleted_at')->orderBy('u.id')
            ->get(['u.id', 'u.email', 'e.id as employee_id', 'e.firstname', 'e.lastname'])->values();
    }

    private function trip(int $route, int $vehicle, Carbon $departure, string $status, array $crew): object
    {
        $minutes = (int) DB::table('transport_routes')->where('id', $route)->value('duration_min');
        $id = DB::table('transport_trips')->insertGetId(['company_id' => $this->company, 'route_id' => $route, 'vehicle_id' => $vehicle, 'departure_at' => $departure,
            'arrival_at' => $departure->copy()->addMinutes($minutes), 'status' => $status, 'notes' => self::NOTE, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($crew as $role => $person) {
            DB::table('transport_trip_crew')->insert(['trip_id' => $id, 'employee_id' => $person->employee_id, 'role' => $role, 'trip_rate' => 0]);
        }

        return DB::table('transport_trips')->where('id', $id)->first();
    }

    private function mark(int $trip, int $location, array $set): void
    {
        DB::table('transport_trip_checkpoints')->insert($set + ['company_id' => $this->company, 'trip_id' => $trip, 'location_id' => $location, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** What the crew reported up to now: each control point the bus has passed, with a little variation between them. */
    private function walk(object $trip, int $delay, bool $lastPhoto = true): void
    {
        $points = TransportControl::points($trip);
        $last = count($points) - 1;
        foreach ($points as $i => $p) {
            $late = $i === 0 ? $delay : max(0, $delay + random_int(-3, 3));
            $set = [];
            if ($p['plan_arrive'] && ($at = $p['plan_arrive']->copy()->addMinutes($late))->lt(now())) {
                $gps = $i % 2 === 0;
                $withPhoto = $i !== $last || $lastPhoto;
                $set += ['arrived_at' => $at, 'arrive_source' => $gps ? 'gps' : 'manual', 'arrive_delay_min' => $late, 'photo' => $withPhoto ? $this->photo($i) : null, 'photo_at' => $withPhoto ? $at->copy()->addMinutes(2) : null];
            }
            if ($p['plan_depart'] && ($at = $p['plan_depart']->copy()->addMinutes($late))->lt(now())) {
                $set += ['departed_at' => $at, 'depart_source' => $i % 2 ? 'gps' : 'manual', 'depart_delay_min' => $late];
            }
            if ($set) {
                $this->mark((int) $trip->id, $p['location_id'], $set);
            }
        }
    }

    private function position(object $trip, float $lat, float $lng, float $speed, Carbon $at): void
    {
        DB::table('transport_vehicle_positions')->insert(['company_id' => $this->company, 'trip_id' => $trip->id, 'vehicle_id' => $trip->vehicle_id, 'lat' => $lat, 'lng' => $lng,
            'speed_kmh' => $speed, 'accuracy_m' => 12, 'source' => 'phone', 'recorded_at' => $at]);
    }

    /** A sample picture for the arrivals, private like the real ones. */
    private function photo(int $n): string
    {
        $n = $n % 3;
        if (! isset($this->photos[$n])) {
            [$r, $g, $b] = [[30, 64, 120], [21, 94, 72], [120, 53, 15]][$n];
            $img = imagecreatetruecolor(1200, 800);
            imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));
            imagefilledrectangle($img, 120, 300, 1080, 620, imagecolorallocate($img, 226, 232, 240));
            imagestring($img, 5, 500, 450, 'FOTO DE EJEMPLO', imagecolorallocate($img, 15, 23, 42));
            ob_start();
            imagejpeg($img, null, 80);
            $this->photos[$n] = (string) CompanyFiles::putPhoto($this->company, 'transport', (string) ob_get_clean(), 'demo', true);
        }

        return $this->photos[$n];
    }

    /** Passengers (part of the bus) and parcels, so the supervisor's departures and manifest have something to show. */
    private function load(object $trip, bool $left): void
    {
        $route = DB::table('transport_routes')->where('id', $trip->route_id)->first(['origin_id', 'destination_id']);
        $bus = DB::table('vehicles')->where('id', $trip->vehicle_id)->first(['seats', 'vip_seats']);
        $warehouse = DB::table('transport_locations')->where('id', $route->origin_id)->value('warehouse_id');
        $product = DB::table('transport_tickets')->where('company_id', $this->company)->where('route_id', $trip->route_id)->value('product_id')
            ?: DB::table('transport_tickets')->where('company_id', $this->company)->value('product_id');
        $seats = (int) ($bus->seats ?: 44);
        $name = fn (array $list) => $list[random_int(0, count($list) - 1)];
        if ($product) {
            $rows = [];
            for ($n = 1, $sold = (int) round($seats * random_int(55, 97) / 100); $n <= $sold; $n++) {
                $rows[] = ['company_id' => $this->company, 'sale_id' => 0, 'product_id' => $product, 'route_id' => $trip->route_id, 'trip_id' => $trip->id, 'seat_number' => $n, 'is_vip' => $n <= (int) $bus->vip_seats ? 1 : 0,
                    'from_location_id' => $route->origin_id, 'to_location_id' => $route->destination_id, 'code' => 'D'.strtoupper(bin2hex(random_bytes(4))), 'boarded_at' => $left ? $trip->departure_at : null, 'warehouse_id' => $warehouse,
                    'doc_type' => 'dni', 'dni' => (string) random_int(10000000, 79999999), 'first_name' => $name(self::FIRST_NAMES), 'last_name' => $name(self::LAST_NAMES), 'luggage' => random_int(0, 1), 'passenger_type' => 'adult', 'price' => 90, 'created_at' => now(), 'updated_at' => now()];
            }
            DB::table('transport_tickets')->insert($rows);
        }
        for ($n = 1, $count = random_int(3, 9); $n <= $count; $n++) {
            [$l, $w, $h] = [random_int(30, 90), random_int(25, 60), random_int(15, 50)];
            DB::table('transport_parcels')->insert(['company_id' => $this->company, 'number' => 'DEMO-'.$trip->id.'-'.$n, 'trip_id' => $trip->id, 'warehouse_id' => $warehouse, 'received_on' => now()->toDateString(),
                'from_location_id' => $route->origin_id, 'to_location_id' => $route->destination_id, 'sender_name' => $name(self::FIRST_NAMES).' '.$name(self::LAST_NAMES), 'receiver_name' => $name(self::FIRST_NAMES).' '.$name(self::LAST_NAMES),
                'description' => $name(self::CARGO), 'cargo_type' => 'general', 'pieces' => 1, 'length_cm' => $l, 'width_cm' => $w, 'height_cm' => $h, 'weight_kg' => random_int(2, 30), 'volume_m3' => round($l * $w * $h / 1000000, 4),
                'price' => random_int(10, 60), 'payment' => 'paid', 'tracking_token' => bin2hex(random_bytes(12)), 'status' => $left ? 'on_board' : 'received', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function build(): void
    {
        $pilots = $this->people('Piloto');
        $hostesses = $this->people('Tripulación');
        $vehicles = DB::table('vehicles')->where('company_id', $this->company)->whereNull('deleted_at')->orderBy('id')->pluck('id')->all();
        $loc = DB::table('transport_locations')->where('company_id', $this->company)->whereNull('deleted_at')->pluck('id', 'name');
        if (count($pilots) < 6 || count($hostesses) < 4 || count($vehicles) < 14) {
            $this->error('This company needs 6 pilots, 4 hostesses and 14 buses.');

            return;
        }
        $now = now();
        // The first pilot and hostess are left for the trip to try: My trip must open that one (a disposable copy of production).
        DB::table('transport_trip_crew as c')->join('transport_trips as t', 't.id', '=', 'c.trip_id')
            ->whereIn('c.employee_id', [$pilots[0]->employee_id, $hostesses[0]->employee_id])->whereIn('t.status', ['scheduled', 'in_progress'])->delete();
        $crew = fn (int $n) => ['pilot' => $pilots[1 + $n % 5], 'hostess' => $hostesses[1 + $n % 3]];

        // On the road.
        foreach (self::ON_ROAD as $n => [$route, $ago, $delay, $gps, $incident]) {
            $trip = $this->trip($route, $vehicles[$n], $now->copy()->subMinutes($ago), 'in_progress', $crew($n));
            $this->walk($trip, $delay, $n !== 10);
            $this->load($trip, true);
            if ($gps === 'stopped') {
                $this->advance($trip, 0, $now);
            } elseif ($gps === 'lost') {
                $this->advance($trip, 70, $now->copy()->subMinutes(14));
            } elseif ($gps === 'live') {
                $this->advance($trip, random_int(70, 95), $now);
            }
            if ($incident) {
                DB::table('transport_trip_reports')->insert(['company_id' => $this->company, 'trip_id' => $trip->id, 'user_id' => $crew($n)['pilot']->id, 'kind' => $incident[0], 'note' => $incident[1], 'photo' => $this->photo($n),
                    'created_at' => $now->copy()->subMinutes(random_int(5, 25)), 'updated_at' => $now]);
            }
        }

        // Already there.
        foreach (self::ARRIVED as $n => [$route, $ago, $left, $late, $photo]) {
            $start = $now->copy()->subMinutes($ago);
            $trip = $this->trip($route, $vehicles[12 + $n % 2], $start, 'completed', $crew($n));
            $this->walk($trip, $left, $photo);
            $points = TransportControl::points($trip);
            $end = end($points);
            $at = min($end['plan_arrive']->copy()->addMinutes($late), $now->copy()->subMinutes(1));
            DB::table('transport_trip_checkpoints')->updateOrInsert(['trip_id' => $trip->id, 'location_id' => $end['location_id']], ['company_id' => $this->company, 'arrived_at' => $at, 'arrive_source' => 'manual', 'arrive_delay_min' => $late,
                'photo' => $photo ? $this->photo($n) : null, 'photo_at' => $photo ? $at : null, 'updated_at' => $now, 'created_at' => $now]);
            $this->load($trip, true);
        }

        // Later today.
        foreach (self::SCHEDULED as $n => [$route, $in]) {
            $this->load($this->trip($route, $vehicles[($n + 8) % count($vehicles)], $now->copy()->addMinutes($in), 'scheduled', $crew($n)), false);
        }

        // The one to try: leaves soon from three terminals of Lima, stops at Trujillo and Chepén.
        $mine = $this->trip(1, $vehicles[14] ?? $vehicles[0], $now->copy()->addMinutes(10), 'scheduled', ['pilot' => $pilots[0], 'hostess' => $hostesses[0]]);
        foreach ([['CAVASSA', 'CV', 10], ['PUENTE NUEVO', 'P.N.', 40], ['LOS OLIVOS', 'T.OLIVOS', 70]] as $i => [$name, $label, $minutes]) {
            DB::table('transport_trip_boardings')->insert(['company_id' => $this->company, 'trip_id' => $mine->id, 'location_id' => (int) $loc[$name], 'label' => $label, 'seq' => $i + 1, 'board_at' => $now->copy()->addMinutes($minutes), 'created_at' => $now, 'updated_at' => $now]);
        }
        $this->load($mine, false);

        // The last two weeks, for Punctuality: each pilot with their own habit.
        $routes = [[1, 'LIMA', 'CHICLAYO'], [3, 'LIMA', 'TRUJILLO'], [9, 'CHEPEN', 'TRUJILLO'], [4, 'CHICLAYO', 'LIMA'], [10, 'TRUJILLO', 'LIMA'], [6, 'CHICLAYO', 'TRUJILLO']];
        for ($day = 1; $day <= 14; $day++) {
            foreach ($routes as $i => [$route, $from, $to]) {
                $n = ($day + $i) % 6;
                $start = $now->copy()->subDays($day)->setTime(6 + $i * 3, 0);
                $trip = $this->trip($route, $vehicles[($day * 3 + $i) % count($vehicles)], $start, 'completed', ['pilot' => $pilots[$n]]);
                [$late, $later] = self::HABIT[$n];
                $arrives = Carbon::parse($trip->arrival_at)->addMinutes($later);
                $this->mark((int) $trip->id, (int) $loc[$from], ['departed_at' => $start->copy()->addMinutes($late), 'depart_source' => 'manual', 'depart_delay_min' => $late]);
                $this->mark((int) $trip->id, (int) $loc[$to], ['arrived_at' => $arrives, 'arrive_source' => 'manual', 'arrive_delay_min' => $later, 'photo' => $this->photo($day + $i), 'photo_at' => $arrives]);
            }
        }

        $this->line('');
        $this->info('Emulated data ready. To try it:');
        $this->line("  pilot     {$pilots[0]->email} ({$pilots[0]->firstname} {$pilots[0]->lastname})");
        $this->line("  hostess   {$hostesses[0]->email} ({$hostesses[0]->firstname} {$hostesses[0]->lastname})");
        $this->line("  trip      #{$mine->id}, leaves in 10 minutes");
    }

    /**
     * One more position for each bus on the road: moving along its route, or stopped where it is. A trip with no position has
     * no GPS and one whose last position is old lost its signal: both stay as they are.
     */
    private function move(): void
    {
        foreach (DB::table('transport_trips')->where('company_id', $this->company)->where('notes', self::NOTE)->where('status', 'in_progress')->get() as $t) {
            $last = DB::table('transport_vehicle_positions')->where('trip_id', $t->id)->orderByDesc('id')->first();
            if (! $last || Carbon::parse($last->recorded_at)->lt(now()->subMinutes(5))) {
                continue;
            }
            if ((float) $last->speed_kmh === 0.0) {
                $this->position($t, (float) $last->lat, (float) $last->lng, 0, now());
            } else {
                $this->advance($t, random_int(70, 95), now());
            }
        }
    }

    /** Where the bus is along its route at that moment, by the time gone and its delay. */
    private function advance(object $t, float $speed, Carbon $at): void
    {
        $path = TransportLegs::path((int) $t->route_id);
        $coords = DB::table('transport_locations')->whereIn('id', array_column($path, 'location_id'))->get(['id', 'lat', 'lng'])->keyBy('id');
        $delay = (int) DB::table('transport_trip_checkpoints')->where('trip_id', $t->id)->max('depart_delay_min');
        $minutes = Carbon::parse($t->departure_at)->diffInMinutes($at, false) - $delay;
        for ($i = 1, $last = count($path) - 1; $i <= $last; $i++) {
            if ($minutes <= $path[$i]['minutes'] || $i === $last) {
                $share = max(0, min(1, ($minutes - $path[$i - 1]['minutes']) / max(1, $path[$i]['minutes'] - $path[$i - 1]['minutes'])));
                $a = $coords[$path[$i - 1]['location_id']];
                $b = $coords[$path[$i]['location_id']];
                $this->position($t, (float) $a->lat + ((float) $b->lat - (float) $a->lat) * $share, (float) $a->lng + ((float) $b->lng - (float) $a->lng) * $share, $speed, $at);

                return;
            }
        }
    }
}
