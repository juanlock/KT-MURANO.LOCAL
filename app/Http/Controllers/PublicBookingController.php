<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Services\FrontPage;
use App\Support\CompanyDomain;
use App\Support\ModuleFlags;
use Carbon\Carbon;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Public "Book an appointment" page (Bookings module), for people without a login.
 *
 *  GET  /book?company=ID            the form (company = its own domain, or ?company=ID)
 *  GET  /book?company=ID&embed=1    same form in its compact iframe layout
 *  POST /book                       creates a PENDING booking for that company and
 *                                   renders the confirmation (or the errors) in place
 *
 * Meant to be iframed by external websites (see Bookings -> Create -> "Online booking
 * page"), so: no X-Frame-Options, and the POST is CSRF-exempt -- inside a cross-site
 * iframe the session cookie is not sent and a CSRF token could never match. Spam is
 * held off like the Helpdesk public form: route throttle, honeypot, minimum
 * time-to-submit (encrypted timestamp rendered with the form).
 *
 * The visitor becomes the company's customer with the same email (else a new one);
 * staff picked here are only a preference, written into the booking notes.
 */
class PublicBookingController extends Controller
{
    private const MIN_SECONDS = 3;
    private const MAX_FORM_AGE = 86400;

    private function formCompany(Request $request): int
    {
        $cid = FrontPage::explicitCompanyId($request);
        abort_unless($cid, 404);
        abort_unless(ModuleFlags::enabled($cid, 'bookings'), 404);

        return $cid;
    }

    private function brand(int $companyId): array
    {
        $co = DB::table('companies')->where('id', $companyId)->first(['name', 'logo']);
        $logo = DB::table('settings')->where('company_id', $companyId)->value('logo') ?: ($co->logo ?? null);
        $url = null;
        if ($logo && ! str_contains($logo, 'logo-default')) {
            $url = Str::startsWith($logo, ['http://', 'https://']) ? $logo
                : (Str::startsWith($logo, ['files/', 'images/', '/']) ? asset(ltrim($logo, '/')) : asset('images/'.$logo));
        }

        return ['name' => $co->name ?? 'Bookings', 'logo' => $url];
    }

    /** Active, sellable services of the company (with a duration read from the note, if any). */
    private function services(int $cid)
    {
        return DB::table('products as p')->where('p.company_id', $cid)->whereNull('p.deleted_at')
            ->where('p.type', 'is_service')->where('p.is_active', 1)->where('p.not_selling', 0)
            ->leftJoin('product_groups as g', fn ($j) => $j->on('g.id', '=', 'p.product_group_id')->whereNull('g.deleted_at'))
            ->orderByRaw('g.name IS NULL')->orderBy('g.name')->orderBy('p.name')
            ->get(['p.id', 'p.name', 'p.price', 'p.note', 'g.name as group_name'])
            ->map(fn ($p) => (object) [
                'id' => $p->id, 'name' => $p->name, 'price' => (float) $p->price, 'group' => $p->group_name,
                'duration' => preg_match('/Duration:\s*(\d+)\s*min/i', (string) $p->note, $m) ? (int) $m[1] : null,
            ]);
    }

    private function staff(int $cid)
    {
        return DB::table('employees')->where('company_id', $cid)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('leaving_date')->orWhere('leaving_date', '>=', now()->toDateString()))
            ->orderBy('firstname')->get(['id', 'firstname', 'lastname'])
            ->map(fn ($e) => (object) ['id' => $e->id, 'name' => trim($e->firstname.' '.$e->lastname)])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /**
     * Render the page. Always rendered straight from the request (never a redirect with
     * flashed data): in a cross-site iframe there is no session cookie, so flashed
     * errors / confirmations would be lost.
     */
    private function page(Request $request, int $cid, array $errors = [], ?array $done = null)
    {
        $embed = $request->boolean('embed');
        $query = CompanyDomain::companyIdForHost($request->getHost()) ? [] : ['company' => $cid];

        return response()->view('booking-public.form', [
            'brand' => $this->brand($cid),
            'services' => $this->services($cid),
            'staff' => $this->staff($cid),
            'embed' => $embed,
            'formQuery' => $query + ($embed ? ['embed' => 1] : []),
            'formTs' => encrypt(now()->timestamp),
            'old' => $errors ? $request->except(['_ts', 'website']) : [],
            'formErrors' => $errors,
            'done' => $done,
        ], $errors ? 422 : 200)->header('X-Robots-Tag', 'noindex')
            // Explicitly embeddable from any site.
            ->header('Content-Security-Policy', 'frame-ancestors *');
    }

    public function create(Request $request)
    {
        return $this->page($request, $this->formCompany($request));
    }

    public function store(Request $request)
    {
        $cid = $this->formCompany($request);
        $services = $this->services($cid);
        $staff = $this->staff($cid);

        $v = Validator::make($request->all(), [
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190',
            'phone' => 'nullable|string|max:40',
            'service_id' => ['required', 'integer', fn ($a, $v, $fail) => $services->firstWhere('id', (int) $v) ? null : $fail(__('The selected service is invalid.'))],
            'staff_id' => ['nullable', 'integer', fn ($a, $v, $fail) => $staff->firstWhere('id', (int) $v) ? null : $fail(__('The selected staff is invalid.'))],
            'appointment_date' => 'required|date_format:Y-m-d|after_or_equal:today|before:+1 year',
            'appointment_time' => 'required|date_format:H:i',
            'special_requests' => 'nullable|string|max:2000',
            '_ts' => 'required|string|max:1000',
        ]);
        if ($v->fails()) {
            return $this->page($request, $cid, $v->errors()->all());
        }
        $d = $v->validated();

        try {
            $elapsed = now()->timestamp - (int) decrypt($d['_ts']);
        } catch (DecryptException $e) {
            $elapsed = null;
        }
        if ($request->filled('website') || $elapsed === null || $elapsed < self::MIN_SECONDS || $elapsed > self::MAX_FORM_AGE) {
            return $this->page($request, $cid, [__('Your request could not be sent. Please wait a few seconds and try again.')]);
        }

        $start = Carbon::parse($d['appointment_date'].' '.$d['appointment_time']);
        if ($start->isPast()) {
            return $this->page($request, $cid, [__('Please choose a time in the future.')]);
        }

        $svc = $services->firstWhere('id', (int) $d['service_id']);
        $person = ! empty($d['staff_id']) ? $staff->firstWhere('id', (int) $d['staff_id']) : null;
        $end = (clone $start)->addMinutes($svc->duration ?: 60);
        $email = strtolower(trim($d['email']));
        $name = trim($d['name']);

        $booking = DB::transaction(function () use ($cid, $d, $svc, $person, $start, $end, $email, $name) {
            $clientId = DB::table('clients')->where('company_id', $cid)->whereNull('deleted_at')
                ->whereRaw('LOWER(email) = ?', [$email])->value('id');
            if (! $clientId) {
                [$first, $last] = str_contains($name, ' ') ? explode(' ', $name, 2) : [$name, null];
                $clientId = DB::table('clients')->insertGetId([
                    'company_id' => $cid, 'name' => $name, 'firstname' => $first, 'lastname' => $last,
                    'code' => (int) DB::table('clients')->lockForUpdate()->max('code') + 1,
                    'email' => $email, 'phone' => $d['phone'] ?? null,
                    'opening_balance' => 0, 'credit_limit' => 0, 'points' => 0, 'is_royalty_eligible' => 0,
                    'notes' => 'Created by the online booking page.',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $last = DB::table('bookings')->lockForUpdate()->latest('id')->value('Ref');
            $ref = $last ? 'BK_'.((int) (explode('_', $last)[1] ?? 1111) + 1) : 'BK_1111';

            $notes = array_filter([
                'Online booking — '.$name.' · '.$email.(! empty($d['phone']) ? ' · '.$d['phone'] : ''),
                $person ? 'Preferred staff: '.$person->name : null,
                ! empty($d['special_requests']) ? 'Request: '.trim($d['special_requests']) : null,
            ]);

            return Booking::create([
                'Ref' => $ref,
                'customer_id' => $clientId,
                'product_id' => $svc->id,
                'product_name' => $svc->name,
                'price' => $svc->price, 'amount' => $svc->price,
                'booking_date' => $start->toDateString(),
                'booking_time' => $start->format('H:i'),
                'booking_end_time' => $end->isSameDay($start) ? $end->format('H:i') : '23:59',
                'status' => 'pending',
                'notes' => implode("\n", $notes),
            ]);
        });

        // Emails (customer + every admin of the company) after the response is sent,
        // so the visitor never waits on SMTP; a mail failure never affects the booking.
        $mail = [
            'booking_id' => $booking->id, 'ref' => $booking->Ref, 'service' => $svc->name, 'price' => $svc->price,
            'date' => $start->translatedFormat('l, F j, Y'), 'time' => $start->format('H:i'),
            'end_time' => $end->isSameDay($start) ? $end->format('H:i') : null,
            'name' => $name, 'email' => $email, 'phone' => $d['phone'] ?? null,
            'staff' => $person->name ?? null, 'requests' => trim((string) ($d['special_requests'] ?? '')) ?: null,
        ];
        dispatch(fn () => \App\Services\BookingNotifier::publicBookingCreated($cid, $mail))->afterResponse();

        return $this->page($request, $cid, [], [
            'ref' => $booking->Ref, 'service' => $svc->name, 'name' => $name,
            'when' => $start->translatedFormat('l, F j, Y').' · '.$start->format('H:i'),
        ]);
    }
}
