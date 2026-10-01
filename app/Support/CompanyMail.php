<?php

namespace App\Support;

use App\Models\Server;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * The mail server a company's automatic emails go out through -- the same
 * per-company SMTP row Settings -> Mail Settings edits (servers.company_id,
 * Server::forCompany) and invoice "Send email" uses. Used by the booking
 * notices and the POS register close-out, which used to pick the wrong one
 * (the first servers row = another company's, or Laravel's default mailer,
 * which this install doesn't configure in .env -> 127.0.0.1:2525, always failing).
 */
class CompanyMail
{
    /**
     * @return array{0: Mailer, 1: ?string, 2: string}  [mailer, from address, from name]
     */
    public static function mailer(int $companyId): array
    {
        $srv = Server::where('company_id', $companyId)->whereNull('deleted_at')->where('host', '!=', '')->whereNotNull('host')->first()
            // A company without its own server yet: the install's first working one.
            ?? Server::whereNull('deleted_at')->where('host', '!=', '')->whereNotNull('host')->orderBy('id')->first();

        $companyName = trim((string) DB::table('companies')->where('id', $companyId)->value('name'))
            ?: (string) (DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->value('CompanyName') ?: 'KcolTech - Murano');

        if (! $srv) {
            return [Mail::mailer(), config('mail.from.address'), $companyName];
        }

        $port = (int) ($srv->port ?: 587);
        $mailer = Mail::build([
            'transport' => 'smtp',
            'host' => $srv->host,
            'port' => $port,
            'scheme' => ($srv->encryption === 'ssl' || $port === 465) ? 'smtps' : 'smtp',
            'username' => $srv->username,
            'password' => $srv->password,
            'timeout' => 20,
        ]);
        $from = $srv->sender_email
            ?: DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->value('email')
            ?: config('mail.from.address');
        $mailer->alwaysFrom($from, $companyName);

        return [$mailer, $from, $companyName];
    }

    /**
     * Who gets a company's admin notices (bookings, POS register open/close):
     *  - EVERY active admin user of that company (users.type = admin) with a
     *    real email -- 2 or 3 admins = 2 or 3 emails;
     *  - no admin with an email: the company email from Settings;
     *  - the SuperAdmin(s) ONLY when that company turned on Mail Settings ->
     *    "Send a copy to the SuperAdmin" (settings.notify_superadmin_copy).
     *
     * @return array<int, array{email: string, name: string, copy: bool}>
     */
    public static function recipients(int $companyId): array
    {
        $person = fn ($u) => trim(($u->firstname ?? '').' '.($u->lastname ?? '')) ?: (string) $u->username;
        $ok = fn ($e) => $e && filter_var($e, FILTER_VALIDATE_EMAIL) && ! self::reserved($e);

        $out = [];
        $admins = DB::table('users')->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereRaw('LOWER(type) = ?', ['admin'])->where('statut', 1)
            ->get(['email', 'firstname', 'lastname', 'username']);
        foreach ($admins as $u) {
            if ($ok($u->email)) {
                $out[strtolower($u->email)] = ['email' => $u->email, 'name' => $person($u), 'copy' => false];
            }
        }
        $settings = DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->first(['email', 'notify_superadmin_copy']);
        if (! $out && $settings && $ok($settings->email)) {
            $out[strtolower($settings->email)] = ['email' => $settings->email, 'name' => '', 'copy' => false];
        }
        if ($settings && $settings->notify_superadmin_copy) {
            $supers = DB::table('users')->whereNull('deleted_at')->whereRaw('LOWER(type) = ?', ['superadmin'])->where('statut', 1)
                ->get(['email', 'firstname', 'lastname', 'username']);
            foreach ($supers as $u) {
                if ($ok($u->email) && ! isset($out[strtolower($u->email)])) {
                    $out[strtolower($u->email)] = ['email' => $u->email, 'name' => $person($u), 'copy' => true];
                }
            }
        }

        return array_values($out);
    }

    /** Just the addresses of recipients(). @return array<string> */
    public static function adminEmails(int $companyId): array
    {
        return array_column(self::recipients($companyId), 'email');
    }

    /** Reserved test domains never get mail (.test/.example/.invalid/.localhost, example.*). */
    public static function reserved(string $email): bool
    {
        return (bool) preg_match('/(\.(test|example|invalid|localhost)|@example\.(com|net|org))$/i', $email);
    }
}
