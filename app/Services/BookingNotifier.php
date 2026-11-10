<?php

namespace App\Services;

use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails for a booking made on the public "Book an appointment" page (/book):
 *  - every active admin of that company (users.type = admin): "new online booking",
 *    with the customer's contact details and a link to the booking. Reply-To = customer.
 *  - NOT the customer (user's rule, 2026-10-07): the email is optional on the form and
 *    the customer hears from us when the booking is CONFIRMED (bookingEvent 'confirmed').
 *
 * Sent through the booking company's own mail server (App\Support\CompanyMail), with
 * the booking's COMPANY as sender name. Never sends to reserved test domains (.test/.example/.invalid/.localhost,
 * example.com/.net/.org) -- logged instead. A mail failure never breaks the booking.
 */
class BookingNotifier
{
    /**
     * @param  array  $b  ref, service, date, time, end_time, price, name, email, phone, staff, requests, booking_id
     * @return array{customer: bool, admins: array<string>}
     */
    public static function publicBookingCreated(int $companyId, array $b, ?Mailer $mailer = null): array
    {
        $co = DB::table('companies')->where('id', $companyId)->first(['name']);
        $settings = DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->first(['CompanyName', 'email', 'CompanyPhone']);
        // companies.name first: a settings row cloned from the template can still say "Murano".
        $company = trim((string) ($co->name ?? '')) ?: (trim((string) ($settings->CompanyName ?? '')) ?: 'Bookings');
        [$mailer, $from] = $mailer ? [$mailer, config('mail.from.address')] : self::mailer($companyId);

        $when = $b['date'].', '.$b['time'].($b['end_time'] ? ' - '.$b['end_time'] : '');
        $rows = [
            'Reference' => $b['ref'], 'Service' => $b['service'], 'Date' => $when,
            'Price' => '$'.number_format((float) $b['price'], 2), 'Staff' => $b['staff'] ?: 'Any staff',
        ];
        $sent = ['customer' => false, 'admins' => []];

        // ---- company admins
        // Every admin of the company (+ SuperAdmin copy when enabled) -- CompanyMail::recipients.
        $admins = collect(\App\Support\CompanyMail::recipients($companyId))
            ->map(fn ($r) => (object) ['email' => $r['email'], 'firstname' => $r['name'], 'lastname' => '', 'username' => $r['name']]);
        $adminRows = $rows + [
            'Customer' => $b['name'], 'Email' => $b['email'] ?: '-', 'Phone' => $b['phone'] ?: '-',
        ] + ($b['requests'] ? ['Special requests' => $b['requests']] : []);
        $link = url('/next/bookings/'.$b['booking_id'].'/edit');
        foreach ($admins as $a) {
            $adminName = trim($a->firstname.' '.$a->lastname) ?: $a->username;
            $ok = self::deliver($mailer, $from, $company, $a->email, $adminName,
                "New online booking - {$b['ref']} - {$b['service']} - {$when}",
                "A new appointment was booked on the online booking page. It is Pending until you confirm it.",
                $adminRows, "Open the booking: {$link}", $b['email'] ?: null, $link,
                \App\Http\Controllers\BookingEmailConfirmController::link((int) $b['booking_id'], (string) $adminName));
            if ($ok) {
                $sent['admins'][] = $a->email;
            }
        }

        return $sent;
    }

    /**
     * Email check of the public form's limits per IP (2026-10-07): from the 4th
     * booking in 24 h from one connection, the booking waits until the visitor
     * opens this link and presses "I agree" (PublicBookingController::verify / agree).
     */
    public static function publicBookingVerify(int $companyId, string $email, string $name, string $link, array $rows, ?Mailer $mailer = null): bool
    {
        $company = trim((string) DB::table('companies')->where('id', $companyId)->value('name')) ?: 'Bookings';
        [$mailer, $from] = $mailer ? [$mailer, config('mail.from.address')] : self::mailer($companyId);

        return self::deliver($mailer, $from, $company, $email, $name,
            "Please confirm your appointment request - {$company}",
            "Hello {$name},\n\nWe received an appointment request with this email. To book it, open the link below and press \"I agree\". If you didn't make this request, just ignore this email.",
            $rows, "Confirm my appointment: {$link}", null, $link);
    }

    /**
     * A booking was registered by staff (Create Booking / calendar) or
     * confirmed: every active admin of the company gets the booking details
     * and a link to it. ($event: 'created' | 'confirmed'.) Online-form
     * bookings use publicBookingCreated() instead; completion uses
     * bookingCompleted() (sent with the invoice).
     *
     * @return array<string> admin emails it was sent to
     */
    public static function bookingEvent(int $companyId, int $bookingId, string $event, ?string $byName = null, ?Mailer $mailer = null): array
    {
        $b = \App\Models\Booking::with(['customer', 'product', 'tray', 'salesPerson'])->find($bookingId);
        if (! $b || ! in_array($event, ['created', 'confirmed'], true)) {
            return [];
        }
        $co = DB::table('companies')->where('id', $companyId)->first(['name']);
        $settings = DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->first(['CompanyName', 'currency_id']);
        $company = trim((string) ($co->name ?? '')) ?: (trim((string) ($settings->CompanyName ?? '')) ?: 'Bookings');
        $sym = ($settings && $settings->currency_id) ? (string) DB::table('currencies')->where('id', $settings->currency_id)->value('symbol') : '$';
        [$mailer, $from] = $mailer ? [$mailer, config('mail.from.address')] : self::mailer($companyId);

        $sp = $b->salesPerson;
        $staff = $sp ? (trim(($sp->firstname ?? '').' '.($sp->lastname ?? '')) ?: $sp->username) : '-';
        $date = $b->booking_date ? \Carbon\Carbon::parse($b->booking_date)->format('l, F j, Y') : '';
        $time = substr((string) $b->booking_time, 0, 5).($b->booking_end_time ? ' - '.substr((string) $b->booking_end_time, 0, 5) : '');
        $service = optional($b->product)->name ?: ($b->product_name ?: '-');
        $customer = $b->customer;

        $rows = [
            'Booking' => $b->Ref,
            'Status' => ucfirst((string) $b->status),
            'Customer' => optional($customer)->name ?: '-',
            'Email' => optional($customer)->email ?: '-',
            'Phone' => optional($customer)->phone ?: '-',
            'Service' => $service,
            'Date' => trim($date.', '.$time, ', '),
            'Price' => $b->price !== null ? $sym.number_format((float) $b->price, 2) : '-',
            'Staff' => $staff,
        ] + ($b->tray ? ['Station' => $b->tray->name] : []) + self::modeRows($b)
          + ($b->notes ? ['Notes' => $b->notes] : []) + ($byName ? [$event === 'created' ? 'Registered by' : 'Confirmed by' => $byName] : []);

        [$subjectStart, $intro] = $event === 'created'
            ? ['New booking', 'A new appointment was registered.']
            : ['Booking confirmed', 'An appointment was confirmed.'];
        $link = url('/next/bookings/'.$b->id.'/edit');

        // Every admin of the company (+ SuperAdmin copy when enabled) -- CompanyMail::recipients.
        $admins = collect(\App\Support\CompanyMail::recipients($companyId))
            ->map(fn ($r) => (object) ['email' => $r['email'], 'firstname' => $r['name'], 'lastname' => '', 'username' => $r['name']]);
        $sent = [];
        foreach ($admins as $a) {
            $adminName = trim($a->firstname.' '.$a->lastname) ?: $a->username;
            $ok = self::deliver($mailer, $from, $company, $a->email, $adminName,
                "{$subjectStart} - {$b->Ref} - {$service} - ".trim($date.' '.substr((string) $b->booking_time, 0, 5)),
                $intro, $rows, "Open the booking: {$link}", optional($customer)->email ?: null, $link,
                // A staff-registered booking still Pending can be confirmed from the email too.
                $b->status === 'pending' ? \App\Http\Controllers\BookingEmailConfirmController::link((int) $b->id, (string) $adminName) : null);
            if ($ok) {
                $sent[] = $a->email;
            }
        }

        // ---- customer (2026-10-07): the booking is confirmed -> "your appointment is
        // confirmed", when they gave an email. Online bookings get no email before this.
        // Never the company's default / Walk-in customer (it often has the business's email).
        if ($event === 'confirmed' && $customer && $customer->email) {
            $s = DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->first(['email', 'CompanyPhone', 'client_id', 'booking_default_client_id']);
            $isDefault = in_array((int) $customer->id, [(int) ($s->client_id ?? 0), (int) ($s->booking_default_client_id ?? 0)], true)
                || stripos((string) $customer->name, 'Walk-in') === 0;
            if (! $isDefault) {
                $companyEmail = filter_var($s->email ?? '', FILTER_VALIDATE_EMAIL) && ! self::reserved($s->email) ? $s->email : null;
                $ok = self::deliver($mailer, $from, $company, $customer->email, $customer->name,
                    "Your appointment is confirmed - {$b->Ref} - {$company}",
                    "Hello {$customer->name},\n\nYour appointment with {$company} is confirmed. See you soon!",
                    ['Booking' => $b->Ref, 'Service' => $service, 'Date' => trim($date.', '.$time, ', '),
                        'Price' => $rows['Price'], 'Staff' => $staff] + self::modeRows($b),
                    'If you need to change or cancel it, simply reply to this email.'.(! empty($s->CompanyPhone) ? " You can also call us at {$s->CompanyPhone}." : ''),
                    $companyEmail);
                if ($ok) {
                    $sent[] = $customer->email;
                }
            }
        }

        return $sent;
    }

    /**
     * A booking was completed and invoiced: every active admin of the company
     * gets the transaction report (customer, service, date/time, staff,
     * station, location, invoice, amount and payment).
     *
     * @return array<string> admin emails it was sent to
     */
    public static function bookingCompleted(int $companyId, int $bookingId, int $saleId, ?Mailer $mailer = null): array
    {
        $b = \App\Models\Booking::with(['customer', 'product', 'tray', 'salesPerson'])->find($bookingId);
        $sale = \App\Models\Sale::find($saleId);
        if (! $b || ! $sale) {
            return [];
        }
        $co = DB::table('companies')->where('id', $companyId)->first(['name']);
        $settings = DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->first(['CompanyName', 'currency_id']);
        $company = trim((string) ($co->name ?? '')) ?: (trim((string) ($settings->CompanyName ?? '')) ?: 'Bookings');
        $sym = ($settings && $settings->currency_id) ? (string) DB::table('currencies')->where('id', $settings->currency_id)->value('symbol') : '$';
        $money = fn ($v) => $sym.number_format((float) $v, 2);
        [$mailer, $from] = $mailer ? [$mailer, config('mail.from.address')] : self::mailer($companyId);

        $sp = $b->salesPerson;
        $staff = $sp ? (trim(($sp->firstname ?? '').' '.($sp->lastname ?? '')) ?: $sp->username) : '-';
        $method = DB::table('payment_sales')->leftJoin('payment_methods', 'payment_methods.id', '=', 'payment_sales.payment_method_id')
            ->where('payment_sales.sale_id', $sale->id)->whereNull('payment_sales.deleted_at')->pluck('payment_methods.name')->filter()->unique()->implode(', ');
        $date = $b->booking_date ? \Carbon\Carbon::parse($b->booking_date)->format('l, F j, Y') : '';
        $time = substr((string) $b->booking_time, 0, 5).($b->booking_end_time ? ' - '.substr((string) $b->booking_end_time, 0, 5) : '');

        $rows = [
            'Booking' => $b->Ref,
            'Customer' => optional($b->customer)->name ?: '-',
            'Service' => optional($b->product)->name ?: ($b->product_name ?: '-'),
            'Date' => trim($date.', '.$time, ', '),
            'Staff' => $staff,
            'Station' => optional($b->tray)->name ?: '-',
            'Location' => (string) DB::table('warehouses')->where('id', $sale->warehouse_id)->value('name') ?: '-',
            'Invoice' => $sale->Ref,
            'Amount' => $money($sale->GrandTotal),
            'Paid' => $money($sale->paid_amount).($method ? ' ('.$method.')' : ''),
        ];
        $link = url('/next/sales/'.$sale->id);

        // Every admin of the company (+ SuperAdmin copy when enabled) -- CompanyMail::recipients.
        $admins = collect(\App\Support\CompanyMail::recipients($companyId))
            ->map(fn ($r) => (object) ['email' => $r['email'], 'firstname' => $r['name'], 'lastname' => '', 'username' => $r['name']]);
        $sent = [];
        foreach ($admins as $a) {
            $ok = self::deliver($mailer, $from, $company, $a->email, trim($a->firstname.' '.$a->lastname) ?: $a->username,
                "Booking completed - {$b->Ref} - invoice {$sale->Ref} - {$money($sale->GrandTotal)}",
                'An appointment was completed and invoiced.',
                $rows, "Open the invoice: {$link}", null, $link);
            if ($ok) {
                $sent[] = $a->email;
            }
        }

        return $sent;
    }

    /** Appointment mode rows (Settings -> Booking -> Appointment mode): where / how it happens. */
    private static function modeRows($b): array
    {
        return match ($b->mode) {
            'remote' => ['Mode' => 'Remote (online)'] + ($b->meeting_link ? ['Meeting link' => $b->meeting_link] : []),
            'onsite' => ['Mode' => 'At the customer\'s site'] + ($b->site_address ? ['Address' => $b->site_address] : []),
            'office' => ['Mode' => 'At our office'],
            default => [],
        };
    }

    /**
     * Reminder before an appointment (bookings:send-reminders): to the
     * customer and to the booking's staff member, with date, time, service,
     * staff and where / how (office, meeting link or the customer's address).
     *
     * @return array<string> emails it was sent to
     */
    public static function bookingReminder(int $companyId, int $bookingId, int $hours, ?Mailer $mailer = null): array
    {
        $b = \App\Models\Booking::with(['customer', 'product', 'tray', 'salesPerson'])->find($bookingId);
        if (! $b) {
            return [];
        }
        $co = DB::table('companies')->where('id', $companyId)->first(['name']);
        $settings = DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->first(['CompanyName', 'email', 'CompanyPhone']);
        $company = trim((string) ($co->name ?? '')) ?: (trim((string) ($settings->CompanyName ?? '')) ?: 'Bookings');
        $companyEmail = filter_var($settings->email ?? '', FILTER_VALIDATE_EMAIL) && ! self::reserved($settings->email) ? $settings->email : null;
        [$mailer, $from] = $mailer ? [$mailer, config('mail.from.address')] : self::mailer($companyId);

        $sp = $b->salesPerson;
        $staff = $sp ? (trim(($sp->firstname ?? '').' '.($sp->lastname ?? '')) ?: $sp->username) : null;
        $date = $b->booking_date ? \Carbon\Carbon::parse($b->booking_date)->format('l, F j, Y') : '';
        $time = substr((string) $b->booking_time, 0, 5).($b->booking_end_time ? ' - '.substr((string) $b->booking_end_time, 0, 5) : '');
        $service = optional($b->product)->name ?: ($b->product_name ?: 'Appointment');
        $customer = $b->customer;
        // Wording from the real date (a booking made 3 h before still says "today").
        $day = $b->booking_date ? \Carbon\Carbon::parse($b->booking_date)->startOfDay() : null;
        $at = 'at '.substr((string) $b->booking_time, 0, 5);
        $when = ! $day ? $at : ($day->isToday() ? "today {$at}" : ($day->isTomorrow() ? "tomorrow {$at}" : 'on '.$day->format('l, F j').' '.$at));

        $rows = [
            'Booking' => $b->Ref,
            'Service' => $service,
            'Date' => trim($date.', '.$time, ', '),
        ] + ($staff ? ['With' => $staff] : []) + self::modeRows($b);
        $sent = [];

        // ---- customer
        if ($customer && $customer->email) {
            $ok = self::deliver($mailer, $from, $company, $customer->email, $customer->name,
                "Reminder: your appointment {$when} - {$service} - {$company}",
                "Hello {$customer->name},\n\nThis is a reminder of your appointment with {$company} {$when}.",
                $rows,
                'If you need to change or cancel it, simply reply to this email.'.(! empty($settings->CompanyPhone) ? " You can also call us at {$settings->CompanyPhone}." : ''),
                $companyEmail);
            if ($ok) {
                $sent[] = $customer->email;
            }
        }

        // ---- staff member
        if ($sp && $sp->email) {
            $link = url('/next/bookings/'.$b->id.'/edit');
            $ok = self::deliver($mailer, $from, $company, $sp->email, $staff,
                "Reminder: appointment {$when} - ".(optional($customer)->name ?: '-')." - {$service}",
                "You have an appointment {$when}.",
                ['Customer' => optional($customer)->name ?: '-', 'Email' => optional($customer)->email ?: '-', 'Phone' => optional($customer)->phone ?: '-'] + $rows,
                "Open the booking: {$link}", optional($customer)->email ?: null, $link);
            if ($ok) {
                $sent[] = $sp->email;
            }
        }

        return $sent;
    }

    /**
     * Hospital appointment reminder to the patient (phase 4, 2026-10-25), in
     * the company's language. Returns the address it went to, or null.
     */
    public static function hospitalAppointmentReminder(int $companyId, int $appointmentId, ?Mailer $mailer = null): ?string
    {
        $ap = DB::table('appointments as a')->leftJoin('patients as p', 'p.id', '=', 'a.patient_id')
            ->leftJoin('doctors as d', 'd.id', '=', 'a.doctor_id')->leftJoin('hospital_departments as h', 'h.id', '=', 'a.department_id')
            ->where('a.id', $appointmentId)->first(['a.reference', 'a.scheduled_at', 'a.reason', 'p.name', 'p.email', 'p.client_id', 'd.name as doctor', 'h.name as department']);
        $email = $ap ? ($ap->email ?: ($ap->client_id ? DB::table('clients')->where('id', $ap->client_id)->value('email') : null)) : null;
        if (! $ap || ! $email || self::reserved($email)) {
            return null;
        }
        $settings = DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->first(['CompanyName', 'email', 'CompanyPhone', 'default_language']);
        $company = trim((string) DB::table('companies')->where('id', $companyId)->value('name')) ?: (trim((string) ($settings->CompanyName ?? '')) ?: 'Hospital');
        $companyEmail = filter_var($settings->email ?? '', FILTER_VALIDATE_EMAIL) && ! self::reserved($settings->email) ? $settings->email : null;
        [$mailer, $from] = $mailer ? [$mailer, config('mail.from.address')] : self::mailer($companyId);
        $es = ($settings->default_language ?? 'en') === 'es';
        $at = \Carbon\Carbon::parse($ap->scheduled_at)->locale($es ? 'es' : 'en');
        $date = $es ? $at->isoFormat('dddd D [de] MMMM [de] YYYY, HH:mm') : $at->format('l, F j, Y, H:i');
        $rows = $es
            ? array_filter(['Cita' => $ap->reference, 'Fecha' => $date, 'Médico' => $ap->doctor, 'Especialidad' => $ap->department, 'Motivo' => $ap->reason])
            : array_filter(['Appointment' => $ap->reference, 'Date' => $date, 'Doctor' => $ap->doctor, 'Department' => $ap->department, 'Reason' => $ap->reason]);
        $phone = ! empty($settings->CompanyPhone) ? $settings->CompanyPhone : null;
        // Confirm / cancel without signing in (signed links, 2026-10-26): fewer no-shows.
        $sign = fn (string $action) => \App\Support\PublicUrl::base($companyId)
            .\Illuminate\Support\Facades\URL::signedRoute('clinic.appointment.answer', ['appointment' => $appointmentId, 'action' => $action], now()->addDays(7), false);
        $actions = $es
            ? [['Confirmar mi cita', $sign('confirm'), '#2e7d32'], ['No podré asistir', $sign('cancel'), '#b91c1c']]
            : [['Confirm my appointment', $sign('confirm'), '#2e7d32'], ["I can't come", $sign('cancel'), '#b91c1c']];
        $ok = $es
            ? self::deliver($mailer, $from, $company, $email, $ap->name, "Recordatorio: su cita del {$at->isoFormat('D/MM HH:mm')} - {$company}",
                "Hola {$ap->name},\n\nLe recordamos su cita en {$company}.", $rows,
                'Llegue 15 minutos antes con su documento.'.($phone ? " Para cambiarla llame al {$phone}." : ''), $companyEmail, null, null, $actions)
            : self::deliver($mailer, $from, $company, $email, $ap->name, "Reminder: your appointment on {$at->format('M j H:i')} - {$company}",
                "Hello {$ap->name},\n\nThis is a reminder of your appointment at {$company}.", $rows,
                'Please arrive 15 minutes early with your ID.'.($phone ? " To change it call {$phone}." : ''), $companyEmail, null, null, $actions);

        return $ok ? $email : null;
    }

    /**
     * The patient's appointment is cancelled because their doctor left (a doctor
     * removed from employees, EmployeeRemoval, 2026-10-26): reception will call
     * to give them another doctor. Company's language. Returns the address, or null.
     */
    public static function clinicRescheduleNotice(int $companyId, int $appointmentId, ?Mailer $mailer = null): ?string
    {
        $ap = DB::table('appointments as a')->leftJoin('patients as p', 'p.id', '=', 'a.patient_id')->leftJoin('doctors as d', 'd.id', '=', 'a.doctor_id')
            ->where('a.id', $appointmentId)->first(['a.reference', 'a.scheduled_at', 'p.name', 'p.email', 'p.client_id', 'd.name as doctor', 'd.specialty']);
        $email = $ap ? ($ap->email ?: ($ap->client_id ? DB::table('clients')->where('id', $ap->client_id)->value('email') : null)) : null;
        if (! $ap || ! $email || self::reserved($email)) {
            return null;
        }
        $settings = DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->first(['CompanyName', 'email', 'CompanyPhone', 'default_language']);
        $company = trim((string) DB::table('companies')->where('id', $companyId)->value('name')) ?: (trim((string) ($settings->CompanyName ?? '')) ?: 'Clinic');
        $companyEmail = filter_var($settings->email ?? '', FILTER_VALIDATE_EMAIL) && ! self::reserved($settings->email) ? $settings->email : null;
        [$mailer, $from] = $mailer ? [$mailer, config('mail.from.address')] : self::mailer($companyId);
        $es = ($settings->default_language ?? 'en') === 'es';
        $at = \Carbon\Carbon::parse($ap->scheduled_at)->locale($es ? 'es' : 'en');
        $date = $es ? $at->isoFormat('dddd D [de] MMMM [de] YYYY, HH:mm') : $at->format('l, F j, Y, H:i');
        $phone = ! empty($settings->CompanyPhone) ? $settings->CompanyPhone : null;
        $rows = $es
            ? array_filter(['Cita' => $ap->reference, 'Fecha' => $date, 'Médico' => $ap->doctor, 'Especialidad' => $ap->specialty])
            : array_filter(['Appointment' => $ap->reference, 'Date' => $date, 'Doctor' => $ap->doctor, 'Specialty' => $ap->specialty]);
        $ok = $es
            ? self::deliver($mailer, $from, $company, $email, $ap->name, "Su cita del {$at->isoFormat('D/MM HH:mm')} será reprogramada - {$company}",
                "Hola {$ap->name},\n\nSu médico ya no atiende en {$company}, así que su cita quedó sin efecto. Nuestra recepción lo contactará para darle una nueva cita con otro especialista.", $rows,
                $phone ? "Si prefiere, llámenos al {$phone}." : '', $companyEmail)
            : self::deliver($mailer, $from, $company, $email, $ap->name, "Your appointment on {$at->format('M j H:i')} will be rescheduled - {$company}",
                "Hello {$ap->name},\n\nYour doctor no longer works at {$company}, so this appointment has been cancelled. Our reception will contact you to book a new one with another specialist.", $rows,
                $phone ? "If you prefer, call us at {$phone}." : '', $companyEmail);

        return $ok ? $email : null;
    }

    /**
     * Satisfaction survey after a consultation (specialist clinic, 2026-10-26):
     * "How likely are you to recommend us? 0-10" (NPS) + a comment, on a
     * public page with the survey's token. Returns the address, or null.
     */
    public static function clinicSurvey(int $companyId, int $surveyId, ?Mailer $mailer = null): ?string
    {
        $s = DB::table('hms_surveys as s')->join('patients as p', 'p.id', '=', 's.patient_id')->leftJoin('doctors as d', 'd.id', '=', 's.doctor_id')
            ->where('s.id', $surveyId)->first(['s.token', 'p.name', 'p.email', 'p.client_id', 'd.name as doctor']);
        $email = $s ? ($s->email ?: ($s->client_id ? DB::table('clients')->where('id', $s->client_id)->value('email') : null)) : null;
        if (! $s || ! $email || self::reserved($email)) {
            return null;
        }
        $settings = DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->first(['CompanyName', 'email', 'default_language']);
        $company = trim((string) DB::table('companies')->where('id', $companyId)->value('name')) ?: (trim((string) ($settings->CompanyName ?? '')) ?: 'Clinic');
        $companyEmail = filter_var($settings->email ?? '', FILTER_VALIDATE_EMAIL) && ! self::reserved($settings->email) ? $settings->email : null;
        [$mailer, $from] = $mailer ? [$mailer, config('mail.from.address')] : self::mailer($companyId);
        $es = ($settings->default_language ?? 'en') === 'es';
        $url = \App\Support\PublicUrl::base($companyId).'/survey/'.$s->token;
        $ok = $es
            ? self::deliver($mailer, $from, $company, $email, $s->name, "¿Cómo fue su consulta? - {$company}",
                "Hola {$s->name},\n\nGracias por atenderse en {$company}".($s->doctor ? " con {$s->doctor}" : '').". Su opinión nos ayuda a mejorar: son 30 segundos.",
                [], '', $companyEmail, null, null, [['Responder la encuesta', $url, '#2563eb']])
            : self::deliver($mailer, $from, $company, $email, $s->name, "How was your visit? - {$company}",
                "Hello {$s->name},\n\nThank you for visiting {$company}".($s->doctor ? " with {$s->doctor}" : '').". Your opinion helps us improve: it takes 30 seconds.",
                [], '', $companyEmail, null, null, [['Answer the survey', $url, '#2563eb']]);

        return $ok ? $email : null;
    }

    private static function deliver(Mailer $mailer, ?string $from, string $company, ?string $to, ?string $toName, string $subject,
        string $intro, array $rows, string $outro, ?string $replyTo = null, ?string $button = null, ?string $confirmUrl = null, array $actions = []): bool
    {
        if (! $to || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if (self::reserved($to)) {
            Log::info('booking mail (reserved domain, not sent)', ['to' => $to, 'subject' => $subject]);

            return false;
        }

        $text = $intro."\n\n".collect($rows)->map(fn ($v, $k) => "{$k}: {$v}")->implode("\n")."\n\n".$outro
            .($confirmUrl ? "\nConfirm it without signing in: {$confirmUrl}" : '')
            .collect($actions)->map(fn ($x) => "\n{$x[0]}: {$x[1]}")->implode('')."\n\n--\n".$company;
        // "Confirm appointment" (signed link, no login) next to "Open booking".
        $confirmBtn = $confirmUrl
            ? ' <a href="'.e($confirmUrl).'" style="display:inline-block;background:#2e7d32;color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px;margin-left:8px">Confirm appointment</a>'
            : '';
        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.5;color:#222;max-width:560px">'
            .'<p>'.nl2br(e($intro)).'</p><table style="border-collapse:collapse;margin:12px 0">'
            .collect($rows)->map(fn ($v, $k) => '<tr><td style="padding:4px 16px 4px 0;color:#666;vertical-align:top">'.e($k).'</td><td style="padding:4px 0;font-weight:600">'.nl2br(e($v)).'</td></tr>')->implode('')
            .'</table>'
            .($button ? '<p><a href="'.e($button).'" style="display:inline-block;background:#b8860b;color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px">Open booking</a>'.$confirmBtn.'</p>' : '<p>'.e($outro).'</p>')
            // Buttons in the company's language (2026-10-26): [label, url, colour].
            .($actions ? '<p>'.collect($actions)->map(fn ($x) => '<a href="'.e($x[1]).'" style="display:inline-block;background:'.e($x[2] ?? '#2e7d32').';color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px;margin:0 8px 8px 0">'.e($x[0]).'</a>')->implode('').'</p>' : '')
            .'<p style="color:#888;font-size:12px;margin-top:24px">'.e($company).'</p></div>';

        try {
            $mailer->html($html, function ($m) use ($to, $toName, $from, $company, $subject, $replyTo, $text) {
                $m->to($to, $toName ?: null)->from($from ?: config('mail.from.address'), $company)->subject($subject);
                if ($replyTo) {
                    $m->replyTo($replyTo);
                }
                $m->getSymfonyMessage()->text($text);
            });

            return true;
        } catch (\Throwable $e) {
            Log::warning('booking mail failed: '.$e->getMessage(), ['to' => $to, 'subject' => $subject]);

            return false;
        }
    }

    private static function reserved(string $email): bool
    {
        return (bool) preg_match('/(\.(test|example|invalid|localhost)|@example\.(com|net|org))$/i', $email);
    }

    /**
     * [Mailer, from address] -- the BOOKING'S COMPANY mail server (Settings ->
     * Mail Settings), not the first servers row (that was another company's).
     */
    private static function mailer(int $companyId): array
    {
        [$mailer, $from] = \App\Support\CompanyMail::mailer($companyId);

        return [$mailer, $from];
    }
}
