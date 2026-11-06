<?php

namespace App\Services\DemoData\Modules;

use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use App\Support\CompanyFiles;
use App\Support\FormSchema;
use App\Support\PlaceCoordinates;
use Illuminate\Support\Facades\DB;

/**
 * Transport examples (2026-11-01) for any passenger transport / tour company (tourism
 * type -- e.g. a competitor of Turismo Cautivo): agencies -- one per city, with an
 * illustrated picture --, the terminals of each agency and an online channel, routes
 * between the agencies, 4 buses (illustrated picture), the crew -- pilots and
 * hostesses paid per trip --, mechanics and office staff, 30 days of completed trips +
 * today's + next week's (a vehicle, 2 pilots, 1-2 hostesses, never double-booked),
 * maintenance in house and at workshops (suppliers), and a complaints book with
 * origin / destination / terminal answers so the Forms dashboard shows the locations
 * and routes with most complaints.
 * Peru (or a Spanish company): southern Peru routes; else US East Coast. A company that
 * already has its agencies and routes keeps them (only buses, crew, trips, maintenance).
 * Pictures are drawn here (no other company's photos or brand).
 */
class TransportModule extends DemoModule
{
    // [name, city, kind, address]
    private const PE_LOCATIONS = [
        ['Agencia Lima', 'Lima', 'agency', 'Av. Javier Prado Este 1100, La Victoria'],
        ['Agencia Ica', 'Ica', 'agency', 'Av. Municipalidad 245'],
        ['Agencia Nasca', 'Nasca', 'agency', 'Av. Los Incas 140'],
        ['Agencia Arequipa', 'Arequipa', 'agency', 'Terrapuerto, Av. Andrés A. Cáceres s/n'],
        ['Agencia Cusco', 'Cusco', 'agency', 'Terminal Terrestre, Av. Velasco Astete'],
        ['Agencia Puno', 'Puno', 'agency', 'Jr. Primero de Mayo 703'],
        ['Terminal Miraflores', 'Lima', 'terminal', 'Av. José Pardo 520, Miraflores'],
        ['Terminal Plaza Norte', 'Lima', 'terminal', 'Av. Tomás Valle s/n, Independencia'],
        ['Terminal Cercado Arequipa', 'Arequipa', 'terminal', 'Calle San Juan de Dios 112'],
        ['Terminal Wanchaq', 'Cusco', 'terminal', 'Av. Garcilaso 210, Wanchaq'],
        ['Venta web', null, 'online', null],
    ];

    // [origin, destination, km, minutes]
    private const PE_ROUTES = [
        [0, 1, 303, 270], [1, 0, 303, 270], [0, 3, 1010, 960], [3, 0, 1010, 960],
        [1, 2, 140, 150], [2, 3, 570, 600], [3, 4, 515, 600], [4, 3, 515, 600],
        [3, 5, 296, 330], [5, 4, 389, 420],
    ];

    private const US_LOCATIONS = [
        ['New York Agency', 'New York', 'agency', '625 8th Ave'],
        ['Philadelphia Agency', 'Philadelphia', 'agency', '1001 Filbert St'],
        ['Washington Agency', 'Washington', 'agency', '50 Massachusetts Ave NE'],
        ['Boston Agency', 'Boston', 'agency', '700 Atlantic Ave'],
        ['Baltimore Agency', 'Baltimore', 'agency', '2110 Haines St'],
        ['Midtown Terminal', 'New York', 'terminal', '350 5th Ave'],
        ['Brooklyn Terminal', 'New York', 'terminal', '1 Flatbush Ave'],
        ['Online sales', null, 'online', null],
    ];

    private const US_ROUTES = [
        [0, 1, 153, 120], [1, 0, 153, 120], [0, 2, 365, 270], [2, 0, 365, 270],
        [0, 3, 346, 255], [3, 0, 346, 255], [1, 4, 165, 135], [4, 2, 64, 70],
    ];

    // [name, make, model, year, color]
    private const BUSES = [
        ['Bus 101', 'Marcopolo', 'Paradiso G7 1800 DD', 2022, '#0e7490'],
        ['Bus 102', 'Scania', 'K410 Irizar i8', 2021, '#7c3aed'],
        ['Bus 103', 'Volvo', 'B450R', 2023, '#b91c1c'],
        ['Bus 104', 'Mercedes-Benz', 'O 500 RSD', 2019, '#15803d'],
    ];

    public function key(): string
    {
        return 'transport';
    }

    public function label(): string
    {
        return 'Transport (Agencies, Terminals, Routes, Buses, Crew, Trips, Maintenance, Complaints book)';
    }

    public function dataTables(): array
    {
        // Trips only: a company's own agencies / terminals / routes are its setup and the demo uses them.
        return ['transport_trips'];
    }

    public function requiredTables(): array
    {
        return ['transport_locations', 'transport_routes', 'transport_trips', 'transport_trip_crew', 'vehicles', 'vehicle_maintenances'];
    }

    public function purge(DemoContext $ctx): void
    {
        $trips = $ctx->demoIds('transport_trips');
        if ($trips) {
            DB::table('transport_trip_crew')->whereIn('trip_id', $trips)->delete();
        }
        // The ticket offices' cashiers: their agency (user_warehouse has no id to tag).
        $users = DB::table('users')->whereIn('id', $ctx->demoIds('users'))->where('email', 'like', '%-pos-%@kcoltech.com')->pluck('id');
        if ($users->isNotEmpty()) {
            DB::table('user_warehouse')->whereIn('user_id', $users)->delete();
        }
    }

    public function generate(DemoContext $ctx): array
    {
        $peru = $ctx->es || stripos($ctx->country, 'per') !== false;
        $L = fn ($en, $es) => $ctx->es ? $es : $en;
        $locDefs = $peru ? self::PE_LOCATIONS : self::US_LOCATIONS;
        $routeDefs = $peru ? self::PE_ROUTES : self::US_ROUTES;

        // A company that already has its agencies and routes (e.g. Turismo Cautivo) keeps
        // them: the buses, crew and trips use those. Else the example ones are created.
        $routes = $this->companyRoutes($ctx);
        if ($routes) {
            $locDefs = DB::table('transport_locations')->where('company_id', $ctx->companyId)->whereNull('deleted_at')->where('active', 1)
                ->orderBy('id')->get(['name', 'city', 'kind'])->map(fn ($l) => [$l->name, $l->city, $l->kind, null])->all();
            $loc = []; // none created
        } else {
            $loc = [];
            foreach ($locDefs as $i => [$name, $city, $kind, $address]) {
                $image = $kind === 'agency' ? $this->picture($ctx, 'agency-'.($i + 1).'.svg', $this->agencySvg($city ?: $name, $i, $L('AGENCY', 'AGENCIA'))) : null;
                $pos = $kind === 'online' ? null : PlaceCoordinates::find($name, $city);
                // A terminal belongs to the agency of its city.
                $hub = $kind === 'terminal' ? collect($locDefs)->search(fn ($l) => $l[2] === 'agency' && $l[1] === $city) : false;
                $loc[$i] = $ctx->insert('transport_locations', ['name' => $name, 'city' => $city, 'kind' => $kind, 'address' => $address,
                    'phone' => $kind === 'online' ? null : $ctx->phone(), 'image' => $image, 'active' => 1,
                    'lat' => $pos[0] ?? null, 'lng' => $pos[1] ?? null, 'hub_id' => $hub !== false ? $loc[$hub] : null]);
            }
            foreach ($routeDefs as [$o, $d, $km, $min]) {
                $routes[] = ['id' => $ctx->insert('transport_routes', ['origin_id' => $loc[$o], 'destination_id' => $loc[$d], 'distance_km' => $km, 'duration_min' => $min, 'active' => 1]),
                    'min' => $min, 'o' => $locDefs[$o][0], 'd' => $locDefs[$d][0]];
            }
        }

        // Buses with their picture.
        $buses = [];
        foreach (self::BUSES as $i => [$name, $make, $model, $year, $color]) {
            $buses[] = $ctx->insert('vehicles', [
                'name' => $name, 'plate_number' => strtoupper(chr(65 + $i)).chr(random_int(65, 90)).chr(random_int(65, 90)).'-'.random_int(100, 999),
                'vin' => strtoupper(substr(md5(uniqid('', true)), 0, 17)), 'make' => $make, 'model' => $model, 'year' => $year,
                'color' => $L('Company colors', 'Colores de la empresa'), 'type' => 'bus', 'status' => 'active', 'warehouse_id' => $ctx->warehouse(),
                'fuel_type' => 'diesel', 'tank_capacity' => 400, 'odometer' => random_int(180000, 640000),
                'purchase_date' => now()->subYears(2026 - $year + 1)->toDateString(), 'purchase_price' => $ctx->pick([185000, 210000, 240000]),
                'insurance_provider' => $peru ? 'Rímac Seguros' : 'Progressive Commercial', 'insurance_policy' => 'SOAT-'.random_int(100000, 999999),
                'insurance_expiry' => now()->addDays(random_int(40, 330))->toDateString(), 'registration_expiry' => now()->addDays(random_int(60, 360))->toDateString(),
                'inspection_expiry' => now()->addDays(random_int(15, 200))->toDateString(),
                'image' => $this->picture($ctx, 'bus-'.($i + 1).'.svg', $this->busSvg($color, $name)), 'notes' => 'Demo data',
            ]);
        }

        // Staff: department, designations, a shift; pilots / hostesses paid per trip.
        $shift = $ctx->demoIds('office_shifts')[0] ?? DB::table('office_shifts')->where('company_id', $ctx->companyId)->whereNull('deleted_at')->value('id')
            ?? $ctx->insert('office_shifts', ['name' => $L('Office 8:00-17:00', 'Oficina 8:00-17:00'), 'monday_in' => '08:00AM', 'monday_out' => '17:00PM',
                'tuesday_in' => '08:00AM', 'tuesday_out' => '17:00PM', 'wednesday_in' => '08:00AM', 'wednesday_out' => '17:00PM',
                'thursday_in' => '08:00AM', 'thursday_out' => '17:00PM', 'friday_in' => '08:00AM', 'friday_out' => '17:00PM']);
        $dept = $ctx->insert('departments', ['department' => $L('Operations', 'Operaciones'), 'department_head' => null]);
        $des = [];
        foreach (['pilot' => ['Pilot', 'Piloto'], 'hostess' => ['Hostess', 'Azafata'], 'maintenance' => ['Mechanic', 'Mecánico'], 'administrative' => ['Ticket office', 'Boletería']] as $k => [$en, $es]) {
            $des[$k] = $ctx->insert('designations', ['department_id' => $dept, 'designation' => $L($en, $es)]);
        }
        $rates = ['pilot' => $peru ? 180 : 220, 'hostess' => $peru ? 90 : 120];
        $staff = ['pilot' => [], 'hostess' => [], 'maintenance' => [], 'administrative' => []];
        foreach (['pilot' => 6, 'hostess' => 4, 'maintenance' => 2, 'administrative' => 2] as $type => $n) {
            for ($i = 0; $i < $n; $i++) {
                [$first, $last] = $ctx->personName();
                if ($type === 'hostess' && $ctx->es && ! $ctx->isFemale($first)) {
                    $first = $ctx->pick(\App\Services\DemoData\DemoContext::ES_FEMALE);
                }
                $crew = in_array($type, ['pilot', 'hostess'], true);
                $staff[$type][] = $ctx->insert('employees', [
                    'firstname' => $first, 'lastname' => $last, 'username' => $first.' '.$last,
                    'email' => strtolower(preg_replace('/[^a-z]/i', '', $first.'.'.$last)).'.'.random_int(10, 99).'@example.com',
                    'phone' => $ctx->phone(), 'gender' => $type === 'hostess' ? 'female' : ($ctx->isFemale($first) ? 'female' : 'male'),
                    'department_id' => $dept, 'designation_id' => $des[$type], 'office_shift_id' => $shift,
                    'joining_date' => now()->subMonths(random_int(4, 40))->toDateString(), 'employment_type' => 'full_time',
                    'staff_type' => $type, 'pay_type' => $crew ? 'per_trip' : null, 'trip_rate' => $crew ? $rates[$type] : null,
                    'pay_effective_from' => $crew ? now()->subDays(21)->toDateString() : null,
                ]);
            }
        }

        // Trips: 2 a day (08:00 / 20:00), 30 days back, today and a week ahead.
        // Pilots: morning crews [0,1] / [0,4] -- pilot 0 drives every morning, the
        // dashboard's "record" over the safe hours --, night crews rotate among 2, 3 and 5.
        // Nobody (and no bus) is ever on two trips at once (trips of up to 16 h).
        $crews = [0 => [[0, 1], [0, 4]], 1 => [[2, 3], [3, 5], [5, 2]]];
        $trips = 0;
        for ($day = -30; $day <= 7; $day++) {
            foreach ([0, 1] as $slot) {
                $n = ($day + 30) * 2 + $slot;
                $route = $routes[$n % count($routes)];
                $dep = now()->startOfDay()->addDays($day)->setTime($slot ? 20 : 8, 0);
                $arr = $dep->copy()->addMinutes($route['min']);
                $status = $arr->isPast() ? ($n % 17 === 5 ? 'cancelled' : 'completed') : ($dep->isPast() ? 'in_progress' : 'scheduled');
                $trip = $ctx->insert('transport_trips', ['route_id' => $route['id'], 'vehicle_id' => $buses[$n % 4], 'departure_at' => $dep, 'arrival_at' => $arr,
                    'status' => $status, 'notes' => null, 'created_by' => $ctx->userId]);
                foreach ($crews[$slot][($day + 30) % count($crews[$slot])] as $p) {
                    DB::table('transport_trip_crew')->insert(['trip_id' => $trip, 'employee_id' => $staff['pilot'][$p], 'role' => 'pilot', 'trip_rate' => $rates['pilot']]);
                }
                // Pilot, copilot and one hostess (2026-11-04): morning trips the first pair, night trips the second, by turns.
                $pair = $slot ? [2, 3] : [0, 1];
                DB::table('transport_trip_crew')->insert(['trip_id' => $trip, 'employee_id' => $staff['hostess'][$pair[($day + 30) % 2]], 'role' => 'hostess', 'trip_rate' => $rates['hostess']]);
                $trips++;
            }
        }

        // Maintenance: in house (mechanic) and at workshops (suppliers).
        $shops = [];
        foreach ([$L('Diesel Workshop South', 'Taller Diésel del Sur'), $L('Tire Express', 'Llantas Express')] as $shop) {
            $shops[] = $ctx->insert('providers', ['name' => $shop, 'code' => (int) DB::table('providers')->max('code') + 1, 'email' => null,
                'phone' => $ctx->phone(), 'city' => $ctx->city(), 'country' => $ctx->country, 'adresse' => $ctx->street()]);
        }
        $maint = [
            [0, 'service', $L('Engine oil and filters', 'Cambio de aceite y filtros'), 'in_house', null, 0, 420, 'completed', -9],
            [1, 'tyres', $L('6 new tyres', 'Cambio de 6 llantas'), 'workshop', 1, null, 5400, 'completed', -5],
            [2, 'repair', $L('Air brake system', 'Sistema de frenos de aire'), 'workshop', 0, null, 2800, 'in_progress', -1],
            [3, 'inspection', $L('Technical inspection', 'Revisión técnica'), 'in_house', null, 1, 150, 'scheduled', 6],
        ];
        foreach ($maint as [$b, $type, $title, $by, $shop, $mech, $cost, $status, $days]) {
            $ctx->insert('vehicle_maintenances', ['vehicle_id' => $buses[$b], 'type' => $type, 'title' => $title, 'service_date' => now()->addDays($days)->toDateString(),
                'cost' => $cost, 'vendor' => null, 'status' => $status, 'performed_by' => $by, 'provider_id' => $shop !== null ? $shops[$shop] : null,
                'mechanic_id' => $mech !== null ? $staff['maintenance'][$mech] : null, 'notes' => 'Demo data', 'created_by' => $ctx->userId]);
        }

        $complaints = $this->complaintsBook($ctx, $locDefs, $routes);

        // Ticket offices (2026-11-03): each agency a Location with its Terminal POS selling tickets.
        $office = $this->ticketOffice($ctx, $routes, $dept, $des['administrative'], (int) $shift);
        if ($office) {
            $office['Ticket sales'] = \App\Support\TransportDemoDay::tickets($ctx->companyId);
            \App\Support\TransportDemoDay::profitData($ctx->companyId, true);
        }

        return [
            'Agencies and terminals' => count($loc), 'Routes' => count($routes), 'Buses' => count($buses),
            'Crew and staff' => array_sum(array_map('count', $staff)), 'Trips' => $trips, 'Maintenance' => count($maint), 'Complaints' => $complaints,
        ] + $office;
    }

    /**
     * The agencies' ticket offices (user's rule, 2026-11-03), when POS and Sales are on:
     * every agency a Location (TransportAgencies::sync), a ticket per route sold at the
     * agency of its origin (service product "Pasaje LIMA - TRUJILLO", price by distance),
     * one cashier per agency (employee + login {slug}-pos-{agency}@kcoltech.com, role
     * Terminal POS, only that agency, the standard employee password) and passengers.
     * The sales themselves come from TransportDemoDay::tickets (30 days back, then every hour).
     */
    private function ticketOffice(DemoContext $ctx, array $routes, int $dept, int $designation, int $shift): array
    {
        $flags = \App\Services\DemoData\DemoRunner::moduleFlags($ctx->companyId);
        if (($flags['pos'] ?? true) === false || ($flags['sales'] ?? true) === false || ! $routes) {
            return [];
        }
        $warehouses = \App\Support\TransportAgencies::sync($ctx->companyId);
        if (! $warehouses) {
            return [];
        }
        $roleId = \App\Support\TransportAgencies::posRole($ctx->companyId);

        $category = $ctx->insert('categories', ['code' => 'PAS'.random_int(100, 999), 'name' => $ctx->es ? 'Pasajes' : 'Tickets']);
        $units = DB::table('units')->whereNull('deleted_at');
        if ($ctx->hasColumn('units', 'company_id')) {
            $units->where('company_id', $ctx->companyId);
        }
        $unit = (int) ($units->value('id') ?? $ctx->insert('units', ['name' => $ctx->es ? 'Pasaje' : 'Ticket', 'ShortName' => 'pas',
            'base_unit' => null, 'operator' => '*', 'operator_value' => 1, 'is_active' => 1]));
        $rows = DB::table('transport_routes as r')->join('transport_locations as o', 'o.id', '=', 'r.origin_id')->join('transport_locations as d', 'd.id', '=', 'r.destination_id')
            ->whereIn('r.id', array_column($routes, 'id'))->get(['r.id', 'r.distance_km', 'o.name as o', 'd.name as d', 'o.warehouse_id as wid']);
        foreach ($rows as $r) {
            $price = max(20, round(((float) $r->distance_km * 0.11) / 5) * 5);
            $product = $ctx->insert('products', [
                'type' => 'is_service', 'code' => 'PAS-'.$r->id, 'Type_barcode' => 'CODE128',
                'name' => ($ctx->es ? 'Pasaje ' : 'Ticket ').$r->o.' - '.$r->d, 'cost' => round($price * 0.55, 2), 'price' => $price,
                'wholesale_price' => $price, 'min_price' => round($price * 0.8, 2), 'category_id' => $category,
                'unit_id' => $unit, 'unit_sale_id' => $unit, 'unit_purchase_id' => $unit,
                'tax_method' => '1', 'TaxNet' => 0, 'stock_alert' => 0, 'is_variant' => 0, 'is_imei' => 0, 'is_active' => 1,
                'image' => 'no-image.png', 'note' => 'Demo data',
            ]);
            // On sale at the agency it leaves from (its POS lists only its own departures).
            foreach ($r->wid ? [(int) $r->wid] : $warehouses as $wid) {
                $ctx->insert('product_warehouse', ['product_id' => $product, 'warehouse_id' => $wid, 'qte' => 0, 'manage_stock' => 0]);
            }
        }

        $slug = \App\Support\CompanyLogins::slug($ctx->companyId);
        $cashiers = 0;
        foreach ($warehouses as $agencyId => $wid) {
            $agency = (string) DB::table('transport_locations')->where('id', $agencyId)->value('name');
            [$first, $last] = $ctx->personName();
            $email = $slug.'-pos-'.\Illuminate\Support\Str::slug($agency, '').'@kcoltech.com';
            if (DB::table('users')->where('email', $email)->exists()) {
                continue;
            }
            $employee = $ctx->insert('employees', [
                'firstname' => $first, 'lastname' => $last, 'username' => "$first $last", 'email' => $email, 'phone' => $ctx->phone(),
                'gender' => $ctx->isFemale($first) ? 'female' : 'male', 'department_id' => $dept, 'designation_id' => $designation, 'office_shift_id' => $shift,
                'joining_date' => now()->subMonths(random_int(3, 30))->toDateString(), 'employment_type' => 'full_time', 'staff_type' => 'administrative',
            ]);
            $userId = (int) DB::table('employees')->where('id', $employee)->value('user_id');
            if ($userId) {
                DB::table('users')->where('id', $userId)->update(['role_id' => $roleId, 'email' => $email, 'statut' => 1, 'is_all_warehouses' => 0, 'record_view' => 0,
                    'password' => \Illuminate\Support\Facades\Hash::make(\App\Support\CompanyLogins::PASSWORDS['employee'])]);
                DB::table('role_user')->where('user_id', $userId)->delete();
                DB::table('role_user')->insert(['user_id' => $userId, 'role_id' => $roleId]);
                DB::table('user_warehouse')->where('user_id', $userId)->delete();
                DB::table('user_warehouse')->insert(['user_id' => $userId, 'warehouse_id' => $wid]);
                $cashiers++;
            }
        }

        // Passengers (customers); the rest of the tickets go to the walk-in customer.
        for ($i = 0; $i < 40; $i++) {
            [$first, $last] = $ctx->personName();
            $ctx->insert('clients', [
                'code' => (int) DB::table('clients')->max('code') + 1, 'name' => "$first $last", 'firstname' => $first, 'lastname' => $last,
                'email' => strtolower(\Illuminate\Support\Str::ascii("$first.$last")).'.demo'.random_int(1000, 9999).'@example.com',
                'phone' => $ctx->phone(), 'country' => $ctx->country, 'city' => $ctx->city(),
            ]);
        }

        return ['Agencies as locations' => count($warehouses), 'Tickets (routes)' => $rows->count(), 'Terminal POS cashiers' => $cashiers, 'Passengers' => 40];
    }

    /**
     * The company's own active routes between its agencies ([] when it has fewer than 2
     * agencies or no route): [id, min, o, d]; a route without hours gets them from the
     * map distance (+25 % road, 60 km/h), else 4 h.
     */
    private function companyRoutes(DemoContext $ctx): array
    {
        if (DB::table('transport_locations')->where('company_id', $ctx->companyId)->whereNull('deleted_at')->where('kind', 'agency')->count() < 2) {
            return [];
        }

        return DB::table('transport_routes as r')->join('transport_locations as o', 'o.id', '=', 'r.origin_id')->join('transport_locations as d', 'd.id', '=', 'r.destination_id')
            ->where('r.company_id', $ctx->companyId)->whereNull('r.deleted_at')->where('r.active', 1)->orderBy('r.id')
            ->get(['r.id', 'r.duration_min', 'r.distance_km', 'o.name as o', 'd.name as d', 'o.lat as olat', 'o.lng as olng', 'd.lat as dlat', 'd.lng as dlng'])
            ->map(function ($r) {
                $min = (int) $r->duration_min;
                if (! $min) {
                    $km = $r->distance_km ?: ($r->olat !== null && $r->dlat !== null ? PlaceCoordinates::km((float) $r->olat, (float) $r->olng, (float) $r->dlat, (float) $r->dlng) * 1.25 : null);
                    $min = $km ? (int) round($km) : 240;
                }

                return ['id' => (int) $r->id, 'min' => min($min, 960), 'o' => $r->o, 'd' => $r->d];
            })->all();
    }

    /** A complaints book (unless the company has one) with answers by type, agency and route. */
    private function complaintsBook(DemoContext $ctx, array $locDefs, array $routes): int
    {
        if (DB::table('forms')->where('company_id', $ctx->companyId)->whereNull('deleted_at')->where('name', 'like', '%eclam%')->exists()) {
            return 0;
        }
        // Where the passenger bought / boarded: any agency or terminal; origin / destination: the agencies.
        $points = array_values(array_map(fn ($l) => $l[0], array_filter($locDefs, fn ($l) => $l[2] !== 'online')));
        $agencies = array_values(array_map(fn ($l) => $l[0], array_filter($locDefs, fn ($l) => $l[2] === 'agency')));
        $types = $ctx->es
            ? ['Atención en agencia', 'Demora en la salida', 'Pérdida de equipaje', 'Cambio de asiento sin autorización', 'Limpieza del bus', 'Venta por internet']
            : ['Service at the agency', 'Late departure', 'Lost luggage', 'Seat changed without consent', 'Bus cleanliness', 'Online sale'];
        $fields = FormSchema::fields([
            ['key' => 'tipo', 'type' => 'select', 'label' => $ctx->es ? 'Tipo:' : 'Type:', 'required' => true, 'width' => 'half', 'options' => $ctx->es ? ['Reclamo', 'Queja'] : ['Claim', 'Complaint']],
            ['key' => 'nombre', 'type' => 'text', 'label' => $ctx->es ? 'Nombre y apellido:' : 'Full name:', 'required' => true, 'width' => 'half'],
            ['key' => 'email', 'type' => 'email', 'label' => $ctx->es ? 'Correo electrónico:' : 'Email:', 'required' => true, 'width' => 'half'],
            ['key' => 'motivo', 'type' => 'select', 'label' => $ctx->es ? 'Seleccione una opción' : 'Choose an option', 'required' => true, 'options' => $types],
            ['key' => 'agencia', 'type' => 'select', 'label' => $ctx->es ? 'Agencia o terminal:' : 'Agency or terminal:', 'width' => 'half', 'options' => $points],
            ['key' => 'origen', 'type' => 'select', 'label' => $ctx->es ? 'Origen del viaje:' : 'Trip origin:', 'width' => 'half', 'options' => $agencies],
            ['key' => 'destino', 'type' => 'select', 'label' => $ctx->es ? 'Destino del viaje:' : 'Trip destination:', 'width' => 'half', 'options' => $agencies],
            ['key' => 'detalle', 'type' => 'textarea', 'label' => $ctx->es ? 'Detalle:' : 'Details:', 'required' => true],
        ]);
        $settings = FormSchema::settings(['language' => $ctx->es ? 'es' : 'en', 'submit_text' => $ctx->es ? 'Enviar' : 'Send', 'notify' => false,
            'number_prefix' => 'REC-', 'success_message' => $ctx->es ? 'Gracias, registramos su reclamo con el número {number}.' : 'Thank you, your complaint number is {number}.']);
        $formId = $ctx->insert('forms', ['name' => $ctx->es ? 'Libro de Reclamaciones' : 'Complaints book', 'public_key' => strtolower(\Illuminate\Support\Str::random(12)),
            'status' => 1, 'fields' => json_encode($fields, JSON_UNESCAPED_UNICODE), 'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE), 'next_number' => 1, 'created_by' => $ctx->userId]);
        $n = 0;
        // Weighted: the busiest agency and route get more complaints, like real life.
        for ($i = 0; $i < 28; $i++) {
            $route = $routes[$i % 3 === 0 ? 0 : random_int(0, count($routes) - 1)];
            [$first, $last] = $ctx->personName();
            $data = ['tipo' => $i % 3 ? ($ctx->es ? 'Queja' : 'Complaint') : ($ctx->es ? 'Reclamo' : 'Claim'), 'nombre' => "$first $last",
                'email' => strtolower(preg_replace('/[^a-z]/i', '', $first.$last)).'@example.com', 'motivo' => $types[$i % 4 === 0 ? 1 : random_int(0, count($types) - 1)],
                'agencia' => $points[$i % 2 === 0 ? 0 : random_int(0, count($points) - 1)], 'origen' => $route['o'], 'destino' => $route['d'],
                'detalle' => $ctx->es ? 'Ejemplo de reclamo para la demostración.' : 'Example complaint for the demo.'];
            $at = now()->subDays(random_int(0, 45))->setTime(random_int(7, 21), random_int(0, 59));
            $ctx->insert('form_submissions', ['form_id' => $formId, 'number' => ++$n, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'is_read' => $i % 3 !== 0, 'ip' => '127.0.0.1', 'created_at' => $at, 'updated_at' => $at]);
        }
        DB::table('forms')->where('id', $formId)->update(['next_number' => $n + 1]);

        return $n;
    }

    private function picture(DemoContext $ctx, string $name, string $svg): string
    {
        return CompanyFiles::putBytes($ctx->companyId, $name === 'bus' || str_starts_with($name, 'bus') ? 'vehicles' : 'transport', $name, $svg);
    }

    /** A coach seen from the side, in the given colour. */
    private function busSvg(string $c, string $label): string
    {
        $l = htmlspecialchars($label, ENT_XML1);

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 360" width="640" height="360">
  <defs><linearGradient id="sky" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#e0f2fe"/><stop offset="1" stop-color="#f8fafc"/></linearGradient></defs>
  <rect width="640" height="360" fill="url(#sky)"/><rect y="292" width="640" height="68" fill="#cbd5e1"/><rect y="318" width="640" height="6" fill="#f8fafc" opacity=".8"/>
  <rect x="60" y="92" width="520" height="184" rx="26" fill="{$c}"/><rect x="60" y="92" width="520" height="40" rx="20" fill="#ffffff" opacity=".18"/>
  <g fill="#0f172a" opacity=".82"><rect x="92" y="120" width="70" height="58" rx="8"/><rect x="172" y="120" width="70" height="58" rx="8"/><rect x="252" y="120" width="70" height="58" rx="8"/><rect x="332" y="120" width="70" height="58" rx="8"/><rect x="412" y="120" width="70" height="58" rx="8"/></g>
  <path d="M492 112 h52 q24 0 30 28 l10 60 h-92z" fill="#0f172a" opacity=".85"/>
  <rect x="60" y="206" width="520" height="14" fill="#ffffff" opacity=".85"/><rect x="500" y="214" width="34" height="56" rx="4" fill="#0f172a" opacity=".55"/>
  <circle cx="160" cy="280" r="34" fill="#111827"/><circle cx="160" cy="280" r="15" fill="#9ca3af"/><circle cx="470" cy="280" r="34" fill="#111827"/><circle cx="470" cy="280" r="15" fill="#9ca3af"/>
  <rect x="566" y="236" width="18" height="12" rx="3" fill="#fde68a"/>
  <text x="96" y="250" font-family="Segoe UI, Arial" font-size="22" font-weight="700" fill="#ffffff">{$l}</text>
</svg>
SVG;
    }

    /** An agency's bus station front with the city's name ("AGENCIA LIMA"). */
    private function agencySvg(string $city, int $i, string $word): string
    {
        $c = ['#0e7490', '#7c3aed', '#b45309', '#15803d', '#be123c', '#1d4ed8'][$i % 6];
        $t = htmlspecialchars($word.' '.mb_strtoupper($city), ENT_XML1);

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 360" width="640" height="360">
  <rect width="640" height="360" fill="#e0f2fe"/><circle cx="560" cy="70" r="34" fill="#fde68a"/><rect y="290" width="640" height="70" fill="#cbd5e1"/>
  <rect x="70" y="120" width="500" height="170" fill="#f8fafc" stroke="#94a3b8" stroke-width="3"/><path d="M50 124 L320 50 L590 124 Z" fill="{$c}"/>
  <rect x="90" y="150" width="460" height="34" fill="{$c}" opacity=".12"/>
  <text x="320" y="176" text-anchor="middle" font-family="Segoe UI, Arial" font-size="24" font-weight="800" fill="{$c}">{$t}</text>
  <g fill="#94a3b8" opacity=".55"><rect x="100" y="200" width="80" height="60"/><rect x="200" y="200" width="80" height="60"/><rect x="360" y="200" width="80" height="60"/><rect x="460" y="200" width="80" height="60"/></g>
  <rect x="295" y="200" width="50" height="90" fill="#334155"/><rect x="40" y="300" width="560" height="8" fill="#f8fafc"/>
</svg>
SVG;
    }
}
