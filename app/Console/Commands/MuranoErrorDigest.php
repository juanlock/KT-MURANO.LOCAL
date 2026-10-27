<?php

namespace App\Console\Commands;

use App\Support\CompanyMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/*
 * Error alerts (2026-10-27; scheduled 07:00): yesterday's ERROR / CRITICAL lines of the
 * Laravel log (daily files laravel-YYYY-MM-DD.log, or laravel.log), grouped by message,
 * emailed to the active SuperAdmin(s). Nothing is sent on a day without errors.
 *
 *   php artisan murano:error-digest [--date=YYYY-MM-DD] [--dry]
 */
class MuranoErrorDigest extends Command
{
    protected $signature = 'murano:error-digest {--date= : Day to report (default yesterday)} {--dry : Print, do not email}';

    protected $description = 'Email the SuperAdmin a summary of the errors logged on a day';

    public function handle(): int
    {
        $day = $this->option('date') ?: now()->subDay()->toDateString();
        $files = array_filter([storage_path("logs/laravel-$day.log"), storage_path('logs/laravel.log')], 'is_file');
        $groups = [];
        $total = 0;
        foreach ($files as $f) {
            $h = fopen($f, 'r');
            while (($line = fgets($h)) !== false) {
                if (! str_starts_with($line, "[$day ") || ! preg_match('/^\[[^\]]+\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.*)$/', $line, $m)) {
                    continue;
                }
                $total++;
                // Same error = same message without numbers / ids.
                $msg = mb_substr(preg_replace('/\{"exception".*$/', '', $m[2]), 0, 220);
                $key = preg_replace('/\d+/', '#', $msg);
                $groups[$key] ??= ['n' => 0, 'sample' => $msg, 'last' => substr($line, 12, 8)];
                $groups[$key]['n']++;
                $groups[$key]['last'] = substr($line, 12, 8);
            }
            fclose($h);
        }
        if (! $total) {
            $this->info("No errors on $day.");

            return self::SUCCESS;
        }
        uasort($groups, fn ($a, $b) => $b['n'] <=> $a['n']);
        $rows = '';
        foreach (array_slice($groups, 0, 20) as $g) {
            $rows .= '<tr><td style="padding:4px 10px;text-align:right;font-weight:700">'.$g['n'].'</td><td style="padding:4px 10px;color:#5b6b82">'.$g['last'].'</td><td style="padding:4px 10px;font-family:monospace;font-size:12px">'.e($g['sample']).'</td></tr>';
        }
        $site = (string) config('app.url');
        $html = '<div style="font-family:Arial,sans-serif;color:#0f2747"><h2 style="color:#03387b">Murano: '.$total.' error(es) el '.$day.'</h2>'
            .'<p>'.count($groups).' tipo(s) distinto(s) en '.e($site).'. Los más frecuentes:</p>'
            .'<table style="border-collapse:collapse;font-size:13px"><tr><th>#</th><th>Última</th><th>Mensaje</th></tr>'.$rows.'</table>'
            .'<p style="color:#94a3b8;font-size:12px">storage/logs en el servidor · murano:error-digest</p></div>';

        if ($this->option('dry')) {
            $this->line("$total errors, ".count($groups).' kinds');
            foreach (array_slice($groups, 0, 10) as $g) {
                $this->line("  {$g['n']}x {$g['sample']}");
            }

            return self::SUCCESS;
        }
        $to = DB::table('users')->whereNull('deleted_at')->where('type', 'SuperAdmin')->where('statut', 1)->pluck('email')
            ->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) && ! CompanyMail::reserved($e))->unique()->values();
        [$mailer] = CompanyMail::mailer(\App\Models\Setting::TEMPLATE_COMPANY_ID);
        foreach ($to as $email) {
            $mailer->html($html, fn ($m) => $m->to($email)->subject("Murano: $total errores el $day ($site)"));
        }
        $this->info("$total errors on $day, sent to ".$to->implode(', '));

        return self::SUCCESS;
    }
}
