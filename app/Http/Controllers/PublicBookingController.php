<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Services\FrontPage;
use App\Support\BookingAvailability;
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

        // Defaults -> Booking -> "Logo on the booking form": its own image, else
        // the company logo above; null = switched off (or nothing to show).
        $s = DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')
            ->first(['booking_form_logo_enabled', 'booking_form_logo', 'booking_form_logo_width']);
        $formLogo = null;
        if ($s && $s->booking_form_logo_enabled) {
            $own = $s->booking_form_logo && file_exists(public_path($s->booking_form_logo))
                ? asset($s->booking_form_logo).'?v='.filemtime(public_path($s->booking_form_logo)) : null;
            $formLogo = ($own || $url) ? ['url' => $own ?: $url, 'width' => (int) ($s->booking_form_logo_width ?: 160)] : null;
        }

        return ['name' => $co->name ?? 'Bookings', 'logo' => $url, 'form_logo' => $formLogo];
    }

    /**
     * Active, sellable services of the company. duration = Product form ->
     * Details -> Service duration, else a "Duration: N min" note, else null
     * (bookings then take 60 minutes).
     */
    private function services(int $cid)
    {
        return DB::table('products as p')->where('p.company_id', $cid)->whereNull('p.deleted_at')
            ->where('p.type', 'is_service')->where('p.is_active', 1)->where('p.not_selling', 0)
            // Product form -> Status -> "Show on booking form"
            ->where('p.show_on_booking_form', 1)
            ->leftJoin('product_groups as g', fn ($j) => $j->on('g.id', '=', 'p.product_group_id')->whereNull('g.deleted_at'))
            ->orderByRaw('g.name IS NULL')->orderBy('g.name')->orderBy('p.name')
            ->get(['p.id', 'p.name', 'p.price', 'p.note', 'p.service_duration', 'g.name as group_name'])
            ->map(fn ($p) => (object) [
                'id' => $p->id, 'name' => $p->name, 'price' => (float) $p->price, 'group' => $p->group_name,
                'duration' => $p->service_duration ? (int) $p->service_duration
                    : (preg_match('/Duration:\s*(\d+)\s*min/i', (string) $p->note, $m) ? (int) $m[1] : null),
            ]);
    }

    /** See App\Support\BookingAvailability::maxConcurrent(). */
    private static function maxConcurrent(array $intervals, int $from, int $to): int
    {
        return BookingAvailability::maxConcurrent($intervals, $from, $to);
    }

    /** Minutes a booking of this service takes (60 when the service has no duration). */
    private function durationOf(?object $svc): int
    {
        return max(1, (int) ($svc->duration ?? 0) ?: 60);
    }

    /**
     * Booked time per day from today on (not cancelled), as [startMin, endMin]
     * pairs -- only times, nothing about who booked. The picker hides start
     * times whose duration would overlap `capacity` of these; store() checks
     * the same with the rows locked.
     * @return array<string, array<int, array{0: int, 1: int}>>
     */
    private function busy(int $cid, ?string $onlyDate = null, bool $lock = false): array
    {
        return $onlyDate
            ? BookingAvailability::busy($cid, $onlyDate, $onlyDate, null, $lock)
            : BookingAvailability::busy($cid, now()->toDateString(), now()->addYear()->toDateString(), null, $lock);
    }

    /**
     * Settings -> Defaults -> Booking -> Online booking hours: what the day /
     * time-slot picker offers, and what store() accepts.
     * @return array{open: string, close: string, slot: int, closed: int[], capacity: int}
     */
    private function hours(int $cid): array
    {
        return BookingAvailability::hours($cid);
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
            'hours' => $this->hours($cid),
            'busy' => $this->busy($cid),
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

    /** US numbers stored as (555) 123-4567; "+..." international kept as typed. */
    private function formatPhone(?string $phone): ?string
    {
        $raw = trim((string) $phone);
        if ($raw === '') {
            return null;
        }
        if (str_starts_with($raw, '+')) {
            return $raw;
        }
        $d = preg_replace('/\D/', '', $raw);
        if (strlen($d) === 11 && $d[0] === '1') {
            $d = substr($d, 1);
        }

        return '('.substr($d, 0, 3).') '.substr($d, 3, 3).'-'.substr($d, 6);
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

        // Same rules as the form's own checks (booking-public/form.blade.php):
        // email user@domain.tld; phone optional, 10 digits (US, optional leading 1)
        // or "+" and 8-15 digits for international.
        $phoneRule = function ($attr, $value, $fail) {
            $raw = trim((string) $value);
            $digits = preg_replace('/\D/', '', $raw);
            $ok = str_starts_with($raw, '+')
                ? strlen($digits) >= 8 && strlen($digits) <= 15
                : strlen($digits) === 10 || (strlen($digits) === 11 && $digits[0] === '1');
            if (! $ok || preg_match('/[^\d\s().+\-]/', $raw)) {
                $fail(__('Please enter a valid phone number: 10 digits, e.g. (555) 123-4567, or + and country code.'));
            }
        };
        $v = Validator::make($request->all(), [
            'name' => 'required|string|max:120',
            'email' => ['required', 'max:190', 'email:rfc,filter', 'regex:/\.[A-Za-z]{2,}$/'],
            'phone' => ['nullable', 'string', 'max:25', $phoneRule],
            'service_id' => ['required', 'integer', fn ($a, $v, $fail) => $services->firstWhere('id', (int) $v) ? null : $fail(__('The selected service is invalid.'))],
            'staff_id' => ['nullable', 'integer', fn ($a, $v, $fail) => $staff->firstWhere('id', (int) $v) ? null : $fail(__('The selected staff is invalid.'))],
            'appointment_date' => 'required|date_format:Y-m-d|after_or_equal:today|before:+1 year',
            'appointment_time' => 'required|date_format:H:i',
            'special_requests' => 'nullable|string|max:2000',
            '_ts' => 'required|string|max:1000',
        ], [
            'email.email' => __('Please enter a valid email address, e.g. name@example.com.'),
            'email.regex' => __('Please enter a valid email address, e.g. name@example.com.'),
        ]);
        if ($v->fails()) {
            return $this->page($request, $cid, $v->errors()->all());
        }
        $d = $v->validated();
        $d['phone'] = $this->formatPhone($d['phone'] ?? null);

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
        $duration = $this->durationOf($svc);

        // Must be one of the picker's slots: an open day, within opening hours,
        // on a slot boundary, and the service's whole duration ending by closing.
        $h = $this->hours($cid);
        $mins = fn (string $hm) => ((int) substr($hm, 0, 2)) * 60 + (int) substr($hm, 3, 2);
        $t = $mins($start->format('H:i'));
        if (in_array($start->dayOfWeek, $h['closed'], true)
            || $t < $mins($h['open']) || $t + $duration > $mins($h['close'])
            || ($t - $mins($h['open'])) % $h['slot'] !== 0) {
            return $this->page($request, $cid, [__('Please choose one of the available times.')]);
        }

        $person = ! empty($d['staff_id']) ? $staff->firstWhere('id', (int) $d['staff_id']) : null;
        $end = (clone $start)->addMinutes($duration);
        $email = strtolower(trim($d['email']));
        $name = trim($d['name']);

        $taken = false;
        $booking = DB::transaction(function () use ($cid, $d, $svc, $person, $start, $end, $email, $name, $t, $duration, $h, &$taken) {
            // Blocked time: with that day's bookings locked, fewer than `capacity`
            // bookings may be running at any moment of the new [start, end). Anyone
            // who picked the same time a moment earlier wins; this one is refused.
            $day = $this->busy($cid, $start->toDateString(), true)[$start->toDateString()] ?? [];
            if (self::maxConcurrent($day, $t, $t + $duration) >= $h['capacity']) {
                $taken = true;

                return null;
            }

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

            // Location: Defaults -> Booking location, else the company default.
            $s = DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->first(['booking_default_warehouse_id', 'warehouse_id']);

            return Booking::create([
                'Ref' => $ref,
                'customer_id' => $clientId,
                'warehouse_id' => ($s->booking_default_warehouse_id ?? null) ?: ($s->warehouse_id ?? null),
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
        if ($taken) {
            return $this->page($request, $cid, [__('Sorry, that time was just booked. Please choose another time.')]);
        }

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
