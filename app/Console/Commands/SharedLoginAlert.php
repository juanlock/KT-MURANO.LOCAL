<?php

namespace App\Console\Commands;

use App\Support\CompanyMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/*
 * Shared-login alert (user's rule, 2026-11-19): a login whose password only an admin changes (users.password_locked -- Turismo's
 * test logins share one password) that signed in from 3 or more different IPs in the last 24 hours is reported by email to the
 * SuperAdmin(s): who, company, the IPs with their times and devices. Once per login per 24 h (a "shared_alert" row in login_events).
 *
 *   php artisan murano:shared-login-alert [--ips=3] [--hours=24] [--dry]
 */
class SharedLoginAlert extends Command
{
    protected $signature = 'murano:shared-login-alert {--ips=3 : Distinct IPs that raise the alert} {--hours=24} {--dry : Print, do not email}';

    protected $description = 'Email the SuperAdmin when a password-locked login signs in from several IPs';

    public function handle(): int
    {
        $min = max(2, (int) $this->option('ips'));
        $since = now()->subHours(max(1, (int) $this->option('hours')));
        $rows = DB::table('login_events as e')->join('users as u', 'u.id', '=', 'e.user_id')
            ->where('u.password_locked', 1)->where('e.event', 'success')->where('e.created_at', '>=', $since)->whereNotNull('e.ip')
            ->orderBy('e.created_at')->get(['e.user_id', 'e.ip', 'e.device', 'e.created_at', 'u.email', 'u.company_id', 'u.firstname', 'u.lastname']);

        $hits = [];
        foreach ($rows->groupBy('user_id') as $uid => $events) {
            if ($events->pluck('ip')->unique()->count() < $min) {
                continue;
            }
            if (DB::table('login_events')->where('user_id', $uid)->where('event', 'shared_alert')->where('created_at', '>=', now()->subDay())->exists()) {
                continue; // already reported today
            }
            $hits[$uid] = $events;
        }
        if (! $hits) {
            $this->info('No login over '.$min.' IPs.');

            return self::SUCCESS;
        }

        $companies = DB::table('companies')->whereIn('id', collect($hits)->map(fn ($e) => $e->first()->company_id)->unique())->pluck('name', 'id');
        $html = '<p>Estos accesos con contraseña compartida entraron desde '.$min.' o más IPs distintas en las últimas '.(int) $this->option('hours').' horas:</p>';
        foreach ($hits as $uid => $events) {
            $u = $events->first();
            $html .= '<h3 style="margin:14px 0 4px">'.e(trim($u->firstname.' '.$u->lastname)).' &lt;'.e($u->email).'&gt; · '.e($companies[$u->company_id] ?? '').'</h3>'
                .'<table cellpadding="4" style="border-collapse:collapse;font-size:13px"><tr><th align="left">IP</th><th align="left">Ingresos</th><th align="left">Último</th><th align="left">Equipo</th></tr>';
            foreach ($events->groupBy('ip') as $ip => $list) {
                $html .= '<tr><td>'.e($ip).'</td><td>'.$list->count().'</td><td>'.e((string) $list->last()->created_at).'</td><td>'.e((string) $list->last()->device).'</td></tr>';
            }
            $html .= '</table>';
            $this->line($u->email.': '.$events->pluck('ip')->unique()->implode(', '));
        }
        if ($this->option('dry')) {
            return self::SUCCESS;
        }

        $to = DB::table('users')->whereNull('deleted_at')->where('type', 'SuperAdmin')->where('statut', 1)->pluck('email')
            ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) && ! CompanyMail::reserved($e))->unique()->values();
        [$mailer] = CompanyMail::mailer(\App\Models\Setting::TEMPLATE_COMPANY_ID);
        foreach ($to as $email) {
            $mailer->html($html, fn ($m) => $m->to($email)->subject('Murano: acceso compartido desde '.$min.'+ IPs ('.count($hits).')'));
        }
        foreach ($hits as $uid => $events) {
            $u = $events->first();
            DB::table('login_events')->insert(['user_id' => $uid, 'company_id' => $u->company_id, 'email' => $u->email, 'channel' => 'web',
                'event' => 'shared_alert', 'ip' => null, 'note' => 'IPs: '.mb_substr($events->pluck('ip')->unique()->implode(', '), 0, 240), 'created_at' => now()]);
        }
        $this->info(count($hits).' login(s) reported to '.$to->implode(', '));

        return self::SUCCESS;
    }
}
