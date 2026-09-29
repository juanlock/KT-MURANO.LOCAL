<?php

/**
 * One-off import: dbbooking.spa_booking_appointments -> Murano bookings of company 3 (NewEra).
 * Run AFTER import_dbbooking_company3.php (services, customers, staff).
 *
 *  - customer: the imported account (spa customer -> same email in company 3), or for a guest
 *    booking the company-3 customer with the same phone / email+name, else a new customer;
 *  - service: the company-3 service product with the same name (removed services -> no product,
 *    noted); price = total_price, or the service price when empty/0; end = start + duration;
 *  - staff: named in the notes (a booking's sales person must be a user with a login);
 *  - status kept (pending / completed / cancelled / confirmed).
 * Each booking's notes carry "[dbbooking #<id>]" -- re-running skips those already imported.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$CO = 3;
$now = now();
$clean = fn ($s) => trim(html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
$digits = fn ($s) => substr(preg_replace('/\D+/', '', (string) $s), -10);
$stats = ['bookings' => 0, 'new_customers' => 0, 'skipped' => 0, 'no_product' => []];

$services = collect(DB::select('SELECT id, name, price, sale_price, duration_minutes FROM dbbooking.spa_booking_services'))->keyBy('id');
$staff = collect(DB::select('SELECT id, name FROM dbbooking.spa_booking_staff'))->pluck('name', 'id');
$spaCustomers = collect(DB::select('SELECT id, email FROM dbbooking.spa_booking_customers'))->pluck('email', 'id');

DB::transaction(function () use ($CO, $now, $clean, $digits, $services, $staff, $spaCustomers, &$stats) {
    $lastRef = (int) preg_replace('/\D+/', '', (string) DB::table('bookings')->where('Ref', 'like', 'BK_%')->orderByDesc('id')->value('Ref'));
    $code = (int) DB::table('clients')->max('code');

    foreach (DB::select('SELECT * FROM dbbooking.spa_booking_appointments ORDER BY appointment_date, appointment_time, id') as $a) {
        $marker = "[dbbooking #{$a->id}]";
        if (DB::table('bookings')->where('notes', 'like', "%{$marker}%")->exists()) {
            $stats['skipped']++;
            continue;
        }

        // ---- customer
        $clientId = null;
        if ($a->customer_id && ($email = strtolower((string) ($spaCustomers[$a->customer_id] ?? '')))) {
            $clientId = DB::table('clients')->where('company_id', $CO)->whereNull('deleted_at')->whereRaw('LOWER(email) = ?', [$email])->value('id');
        }
        $name = $clean($a->name) ?: 'Guest';
        $email = strtolower(trim((string) $a->email)) ?: null;
        if (! $clientId && ($ph = $digits($a->phone))) {
            $clientId = DB::table('clients')->where('company_id', $CO)->whereNull('deleted_at')
                ->whereRaw("RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 10) = ?", [$ph])->value('id');
        }
        if (! $clientId && $email) {
            $clientId = DB::table('clients')->where('company_id', $CO)->whereNull('deleted_at')
                ->whereRaw('LOWER(email) = ?', [$email])->where('name', $name)->value('id');
        }
        if (! $clientId) {
            [$first, $last] = str_contains($name, ' ') ? explode(' ', $name, 2) : [$name, null];
            $clientId = DB::table('clients')->insertGetId([
                'company_id' => $CO, 'name' => $name, 'firstname' => $first, 'lastname' => $last, 'code' => ++$code,
                'email' => $email, 'phone' => $a->phone, 'country' => 'United States',
                'opening_balance' => 0, 'credit_limit' => 0, 'points' => 0, 'is_royalty_eligible' => 0,
                'notes' => "Imported from a booking-site guest appointment (dbbooking #{$a->id}).",
                'created_at' => $a->created_at ?: $now, 'updated_at' => $now,
            ]);
            $stats['new_customers']++;
        }

        // ---- service
        $svc = $a->service_id ? ($services[$a->service_id] ?? null) : null;
        $svcName = $svc ? $clean($svc->name) : null;
        $product = $svcName ? DB::table('products')->where('company_id', $CO)->whereNull('deleted_at')->where('name', $svcName)->first(['id', 'name', 'price']) : null;
        if (! $product) {
            $stats['no_product'][] = $a->id;
        }
        $price = (float) ($a->total_price ?? 0) > 0 ? (float) $a->total_price : (float) ($product->price ?? 0);
        $duration = (int) ($svc->duration_minutes ?? 60) ?: 60;
        $start = \Carbon\Carbon::parse($a->appointment_date.' '.($a->appointment_time ?: '09:00:00'));
        $end = (clone $start)->addMinutes($duration);

        $notes = array_filter([
            $a->special_requests ? 'Request: '.$clean($a->special_requests) : null,
            $a->admin_notes ? 'Admin notes: '.$clean($a->admin_notes) : null,
            $a->staff_id ? 'Staff: '.($staff[$a->staff_id] ?? '#'.$a->staff_id) : null,
            ! $product ? 'Service: '.($svcName ?: ($a->service_id ? 'service #'.$a->service_id.' (removed on the booking site)' : 'none')) : null,
            "Imported from the booking site {$marker}",
        ]);

        DB::table('bookings')->insert([
            'Ref' => 'BK_'.(++$lastRef),
            'customer_id' => $clientId,
            'product_id' => $product->id ?? null,
            'product_name' => $product->name ?? $svcName,
            'price' => $price, 'amount' => $price,
            'booking_date' => $start->toDateString(),
            'booking_time' => $start->format('H:i:s'),
            'booking_end_time' => $end->isSameDay($start) ? $end->format('H:i:s') : '23:59:00',
            'status' => in_array($a->status, ['pending', 'confirmed', 'cancelled', 'completed'], true) ? $a->status : 'pending',
            'notes' => implode("\n", $notes),
            'created_at' => $a->created_at ?: $now, 'updated_at' => $now,
        ]);
        $stats['bookings']++;
    }
});

echo json_encode($stats, JSON_PRETTY_PRINT).PHP_EOL;
