<?php

namespace App\Services;

use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails for a booking made on the public "Book an appointment" page (/book):
 *  - the customer: "we received your request" (it is Pending until the salon confirms);
 *  - every active admin of that company (users.type = admin): "new online booking",
 *    with the customer's contact details and a link to the booking. Reply-To = customer.
 *
 * Sent through the install's mail server (servers table), else the default mailer, with
 * the booking's COMPANY as sender name (the server row's sender_name belongs to one
 * company). Never sends to reserved test domains (.test/.example/.invalid/.localhost,
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
        // companies.name first: a settings row cloned from the template can still say "Stocky".
        $company = trim((string) ($co->name ?? '')) ?: (trim((string) ($settings->CompanyName ?? '')) ?: 'Bookings');
        $companyEmail = filter_var($settings->email ?? '', FILTER_VALIDATE_EMAIL) && ! self::reserved($settings->email) ? $settings->email : null;
        [$mailer, $from] = $mailer ? [$mailer, config('mail.from.address')] : self::mailer();

        $when = $b['date'].', '.$b['time'].($b['end_time'] ? ' - '.$b['end_time'] : '');
        $rows = [
            'Reference' => $b['ref'], 'Service' => $b['service'], 'Date' => $when,
            'Price' => '$'.number_format((float) $b['price'], 2), 'Staff' => $b['staff'] ?: 'Any staff',
        ];
        $sent = ['customer' => false, 'admins' => []];

        // ---- customer
        $intro = "Hello {$b['name']},\n\nThank you for booking with {$company}. We received your appointment request "
            ."and will contact you to confirm it.";
        $sent['customer'] = self::deliver($mailer, $from, $company, $b['email'], $b['name'],
            "Appointment request received - {$b['ref']} - {$company}",
            $intro, $rows + ($b['requests'] ? ['Your request' => $b['requests']] : []),
            'If you need to change or cancel it, simply reply to this email.'.(! empty($settings->CompanyPhone) ? " You can also call us at {$settings->CompanyPhone}." : ''),
            $companyEmail);

        // ---- company admins
        $admins = DB::table('users')->where('company_id', $companyId)->whereNull('deleted_at')
            ->where('type', 'admin')->where('statut', 1)->whereNotNull('email')->get(['email', 'firstname', 'lastname', 'username']);
        $adminRows = $rows + [
            'Customer' => $b['name'], 'Email' => $b['email'], 'Phone' => $b['phone'] ?: '-',
        ] + ($b['requests'] ? ['Special requests' => $b['requests']] : []);
        $link = url('/next/bookings/'.$b['booking_id'].'/edit');
        foreach ($admins as $a) {
            $ok = self::deliver($mailer, $from, $company, $a->email, trim($a->firstname.' '.$a->lastname) ?: $a->username,
                "New online booking - {$b['ref']} - {$b['service']} - {$when}",
                "A new appointment was booked on the online booking page. It is Pending until you confirm it.",
                $adminRows, "Open the booking: {$link}", $b['email'], $link);
            if ($ok) {
                $sent['admins'][] = $a->email;
            }
        }

        return $sent;
    }

    private static function deliver(Mailer $mailer, ?string $from, string $company, ?string $to, ?string $toName, string $subject,
        string $intro, array $rows, string $outro, ?string $replyTo = null, ?string $button = null): bool
    {
        if (! $to || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if (self::reserved($to)) {
            Log::info('booking mail (reserved domain, not sent)', ['to' => $to, 'subject' => $subject]);

            return false;
        }

        $text = $intro."\n\n".collect($rows)->map(fn ($v, $k) => "{$k}: {$v}")->implode("\n")."\n\n".$outro."\n\n--\n".$company;
        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;line-height:1.5;color:#222;max-width:560px">'
            .'<p>'.nl2br(e($intro)).'</p><table style="border-collapse:collapse;margin:12px 0">'
            .collect($rows)->map(fn ($v, $k) => '<tr><td style="padding:4px 16px 4px 0;color:#666;vertical-align:top">'.e($k).'</td><td style="padding:4px 0;font-weight:600">'.nl2br(e($v)).'</td></tr>')->implode('')
            .'</table>'
            .($button ? '<p><a href="'.e($button).'" style="display:inline-block;background:#b8860b;color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px">Open booking</a></p>' : '<p>'.e($outro).'</p>')
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

    /** [Mailer, from address] -- the install's mail server (servers table), else the default mailer. */
    private static function mailer(): array
    {
        $srv = DB::table('servers')->whereNull('deleted_at')->first();
        if ($srv && $srv->host) {
            $port = (int) ($srv->port ?: 587);

            return [Mail::build([
                'transport' => 'smtp',
                'host' => $srv->host,
                'port' => $port,
                'scheme' => ($srv->encryption === 'ssl' || $port === 465) ? 'smtps' : 'smtp',
                'username' => $srv->username,
                'password' => $srv->password,
                'timeout' => 20,
            ]), $srv->sender_email ?: config('mail.from.address')];
        }

        return [Mail::mailer(), config('mail.from.address')];
    }
}
