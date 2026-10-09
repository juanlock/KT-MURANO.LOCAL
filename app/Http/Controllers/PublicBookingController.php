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
 * The visitor becomes the company's customer with the same email (never the
 * default / Walk-in customer; never by phone), else a new one; the staff picked
 * here becomes the booking's staff (the employee's login user) and is noted too.
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
            ->first(['booking_form_logo_enabled', 'booking_form_logo', 'booking_form_logo_width', 'booking_hide_logo', 'booking_bg_color']);
        $formLogo = null;
        if ($s && $s->booking_form_logo_enabled) {
            $own = $s->booking_form_logo && file_exists(public_path($s->booking_form_logo))
                ? asset($s->booking_form_logo).'?v='.filemtime(public_path($s->booking_form_logo)) : null;
            $formLogo = ($own || $url) ? ['url' => $own ?: $url, 'width' => (int) ($s->booking_form_logo_width ?: 160)] : null;
        }

        // Online Booking -> Look: no logo at all / own background color.
        $hideLogo = (bool) ($s->booking_hide_logo ?? false);
        $bg = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($s->booking_bg_color ?? '')) ? $s->booking_bg_color : null;

        return ['name' => $co->name ?? 'Bookings', 'logo' => $url, 'form_logo' => $hideLogo ? null : $formLogo,
            'hide_logo' => $hideLogo, 'bg' => $bg];
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
    private function page(Request $request, int $cid, array $errors = [], ?array $done = null, ?array $verify = null)
    {
        $embed = $request->boolean('embed');
        $query = CompanyDomain::companyIdForHost($request->getHost()) ? [] : ['company' => $cid];
        $exit = $this->exitUrl($request, $cid);
        // The website the code was put on travels with the form (Start over, submit).
        $return = $exit && $request->filled('return') && $exit === $request->query('return') ? ['return' => $exit] : [];

        return response()->view('booking-public.form', [
            'verify' => $verify,
            'brand' => $this->brand($cid),
            'services' => $this->services($cid),
            'staff' => $this->staff($cid),
            'hours' => $this->hours($cid),
            'busy' => $this->busy($cid),
            'embed' => $embed,
            'formQuery' => $query + ($embed ? ['embed' => 1] : []) + $return,
            'formTs' => encrypt(now()->timestamp),
            'old' => $errors ? $request->except(['_ts', 'website']) : [],
            'formErrors' => $errors,
            'done' => $done,
            'exitUrl' => $exit,
            // This connection already made 3 bookings today: the email is needed (check by email).
            'needEmail' => $this->ipCheck((string) $request->ip()) === 'verify',
        ], $errors ? 422 : 200)->header('X-Robots-Tag', 'noindex')
            // Explicitly embeddable from any site.
            ->header('Content-Security-Policy', 'frame-ancestors *');
    }

    /** Online Booking -> Websites & codes: the company's websites (valid http(s) URLs). */
    public static function websites(int $cid): array
    {
        $raw = (string) DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->value('booking_websites');

        return array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', $raw)),
            fn ($u) => (bool) preg_match('~^https?://[^\s/]+~i', $u) && filter_var($u, FILTER_VALIDATE_URL))));
    }

    /**
     * Where "Exit" / "Close" go: the ?return= website of the code (only one of the
     * company's websites -- never any address, so the page can't be used to
     * redirect people elsewhere), else the first website, else the company's
     * own website (Website settings -> Website URL).
     */
    private function exitUrl(Request $request, int $cid): ?string
    {
        $sites = self::websites($cid);
        $ret = trim((string) $request->query('return'));
        // Also the company's own website here (/{website path}, relative: the
        // imported website's booking frame -- Website pages -> "Use our booking calendar").
        $own = \App\Support\WebsitePaths::pathOf($cid);
        if ($ret !== '' && (in_array($ret, $sites, true) || ($own && $ret === '/'.$own))) {
            return $ret;
        }
        if ($sites) {
            return $sites[0];
        }

        return \App\Support\WebsitePaths::pathOf($cid) ? \App\Support\WebsitePaths::url('', $cid) : null;
    }

    /* ---------- limits per IP (user's rule, 2026-10-07) ---------- */
    private const MAX_PER_10_MIN = 5;   // bookings from one IP in 10 minutes
    private const FREE_PER_DAY = 3;     // after these (24 h), an email check is needed
    private const VERIFIED_FOR_HOURS = 24;

    /** null = OK; 'limit' = too many; 'verify' = needs the email check first. */
    private function ipCheck(string $ip): ?string
    {
        $q = fn () => DB::table('booking_public_requests')->where('ip', $ip);
        if ($q()->where('created_at', '>=', now()->subMinutes(10))->count() >= self::MAX_PER_10_MIN) {
            return 'limit';
        }
        $made = $q()->whereNotNull('booking_id')->where('created_at', '>=', now()->subDay())->count();
        $verified = $q()->where('verified_at', '>=', now()->subHours(self::VERIFIED_FOR_HOURS))->exists();

        return $made >= self::FREE_PER_DAY && ! $verified ? 'verify' : null;
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
            // Optional (2026-10-07): only the name is required; an email given must be valid.
            'email' => ['nullable', 'max:190', 'email:rfc,filter', 'regex:/\.[A-Za-z]{2,}$/'],
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


        // Limits per connection (IP): 5 bookings every 10 minutes; from the 4th
        // in 24 hours the visitor confirms by email ("I agree") before it is booked.
        $ip = (string) $request->ip();
        $gate = $this->ipCheck($ip);
        if ($gate === 'limit') {
            return $this->page($request, $cid, [__('Too many appointments from this connection. Please try again in a few minutes.')]);
        }
        $d = array_diff_key($d, ['_ts' => 1]);
        if ($gate === 'verify') {
            if (empty($d['email'])) {
                return $this->page($request, $cid, [__('Please enter your email: we will send you a link to confirm this appointment.')]);
            }
            $token = Str::random(48);
            DB::table('booking_public_requests')->insert([
                'company_id' => $cid, 'ip' => $ip, 'email' => strtolower(trim($d['email'])), 'token' => $token,
                'payload' => json_encode($d + ['_query' => $request->query()]), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $link = route('booking.public.verify', ['token' => $token]);
            $summary = [
                'Service' => $svc->name, 'Date' => $start->translatedFormat('l, F j, Y').' · '.$start->format('g:i A'),
            ];
            dispatch(fn () => \App\Services\BookingNotifier::publicBookingVerify($cid, $d['email'], trim($d['name']), $link, $summary))->afterResponse();

            return $this->page($request, $cid, [], null, ['state' => 'sent', 'email' => $d['email']]);
        }

        return $this->finish($request, $cid, $d, $ip);
    }

    /**
     * Creates the booking from the checked form values (store(), or the "I agree"
     * link of the email check), logs it for the IP limits, emails the admins and
     * shows the thank-you screen.
     */
    private function finish(Request $request, int $cid, array $d, string $ip, ?int $requestId = null)
    {
        $svc = $this->services($cid)->firstWhere('id', (int) $d['service_id']);
        $person = ! empty($d['staff_id']) ? $this->staff($cid)->firstWhere('id', (int) $d['staff_id']) : null;
        $start = Carbon::parse($d['appointment_date'].' '.$d['appointment_time']);
        if (! $svc || $start->isPast()) {
            return $this->page($request, $cid, [__('Please choose one of the available times.')]);
        }
        $duration = $this->durationOf($svc);
        $h = $this->hours($cid);
        $t = ((int) $start->format('H')) * 60 + (int) $start->format('i');
        $end = (clone $start)->addMinutes($duration);
        $email = strtolower(trim((string) ($d['email'] ?? ''))) ?: null;
        $name = trim($d['name']);

        $taken = false;
        // This page sends its own "new online booking" email below.
        \App\Observers\BookingObserver::$muteCreatedMail = true;
        $booking = DB::transaction(function () use ($cid, $d, $svc, $person, $start, $end, $email, $name, $t, $duration, $h, &$taken) {
            // Blocked time: with that day's bookings locked, fewer than `capacity`
            // bookings may be running at any moment of the new [start, end). Anyone
            // who picked the same time a moment earlier wins; this one is refused.
            $day = $this->busy($cid, $start->toDateString(), true)[$start->toDateString()] ?? [];
            if (self::maxConcurrent($day, $t, $t + $duration) >= $h['capacity']) {
                $taken = true;

                return null;
            }

            // Existing customer = same email IN THIS COMPANY (a customer is
            // unique by email + company; the same email or phone may belong to
            // other people in other companies -- the phone never identifies
            // anyone). Never the company's default / Walk-in customer, which
            // often carries the business's own email and would swallow visitors.
            // No email given = a new customer (never matched by name or phone).
            $defaults = DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->first(['client_id', 'booking_default_client_id']);
            $exclude = array_values(array_filter([(int) ($defaults->client_id ?? 0), (int) ($defaults->booking_default_client_id ?? 0)]));
            $clientId = $email === null ? null : DB::table('clients')->where('company_id', $cid)->whereNull('deleted_at')
                ->when($exclude, fn ($q) => $q->whereNotIn('id', $exclude))
                ->where('name', 'not like', 'Walk-in%')
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
                'Online booking — '.implode(' · ', array_filter([$name, $email, $d['phone'] ?? null])),
                $person ? 'Preferred staff: '.$person->name : null,
                ! empty($d['special_requests']) ? 'Request: '.trim($d['special_requests']) : null,
            ]);

            // Location: Defaults -> Booking location, else the company default.
            $s = DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->first(['booking_default_warehouse_id', 'warehouse_id']);

            return Booking::create([
                'Ref' => $ref,
                'customer_id' => $clientId,
                // The staff picked on the form (an employee) is the booking's
                // staff -- their login user -- so it shows on their calendar.
                'sales_person_id' => $person ? (DB::table('employees')->where('id', $person->id)->value('user_id') ?: null) : null,
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
        \App\Observers\BookingObserver::$muteCreatedMail = false;
        if ($taken) {
            return $this->page($request, $cid, [__('Sorry, that time was just booked. Please choose another time.')]);
        }

        // For the limits per IP.
        if ($requestId) {
            DB::table('booking_public_requests')->where('id', $requestId)->update(['booking_id' => $booking->id, 'updated_at' => now()]);
        } else {
            DB::table('booking_public_requests')->insert(['company_id' => $cid, 'ip' => $ip, 'email' => $email, 'booking_id' => $booking->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        // Email to every admin of the company (not the customer: they get theirs when
        // the booking is confirmed -- BookingNotifier) after the response is sent,
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
            'when' => $start->translatedFormat('l, F j, Y').' · '.$start->format('g:i A'),
        ]);
    }

    /* ---------- email check: "I agree" (GET shows it, POST books) ---------- */

    private function pendingRequest(string $token): ?object
    {
        return DB::table('booking_public_requests')->where('token', $token)->whereNull('booking_id')
            ->where('created_at', '>=', now()->subDay())->first();
    }

    /** The request's company and website (?return=); the email link opens as a full page, never embedded. */
    private function asRequest(Request $request, object $r): Request
    {
        $q = (array) (json_decode((string) $r->payload, true)['_query'] ?? []);
        $request->query->replace(array_intersect_key($q, array_flip(['company', 'return'])) + ['company' => $r->company_id]);

        return $request;
    }

    public function verify(Request $request, string $token)
    {
        $r = $this->pendingRequest($token);
        if (! $r) {
            return response('<!doctype html><meta name="viewport" content="width=device-width"><p style="font:16px system-ui;padding:24px">'.e(__('This link is no longer valid. Please make your appointment again.')).'</p>', 404);
        }
        $request = $this->asRequest($request, $r);
        $d = json_decode((string) $r->payload, true);
        $svc = $this->services($r->company_id)->firstWhere('id', (int) ($d['service_id'] ?? 0));
        $when = Carbon::parse($d['appointment_date'].' '.$d['appointment_time']);

        return $this->page($request, (int) $r->company_id, [], null, [
            'state' => 'agree', 'token' => $token, 'name' => $d['name'] ?? '', 'email' => $r->email,
            'service' => $svc->name ?? '-', 'when' => $when->translatedFormat('l, F j, Y').' · '.$when->format('g:i A'),
        ]);
    }

    public function agree(Request $request, string $token)
    {
        $r = $this->pendingRequest($token);
        if (! $r) {
            return response('<!doctype html><meta name="viewport" content="width=device-width"><p style="font:16px system-ui;padding:24px">'.e(__('This link is no longer valid. Please make your appointment again.')).'</p>', 404);
        }
        $request = $this->asRequest($request, $r);
        // The connection that made the request may book again (24 h).
        DB::table('booking_public_requests')->where('id', $r->id)->update(['verified_at' => now(), 'token' => null, 'updated_at' => now()]);

        return $this->finish($request, (int) $r->company_id, json_decode((string) $r->payload, true), (string) $r->ip, (int) $r->id);
    }
}
