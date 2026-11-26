<?php

namespace App\Console\Commands;

use App\Services\JelafSync;
use Illuminate\Console\Command;

/**
 * Nightly import of Turismo Cautivo's Jelaf / AuraSoft reports (2026-11-26: "que cada noche a las 10 pm Murano importe los
 * Excel que necesita"). Runs every report of JelafSync -- Producción por bus of the last --days days, Control de Embarque of
 * yesterday and today (a day still running is replaced the next night) -- each one recorded in transport_jelaf_runs (the
 * history of Integración -> Jelaf automático, where the same can be run by hand). Log: storage/logs/jelaf-sync-YYYY-MM-DD.log.
 *
 *   php artisan transport:jelaf-sync [--company=] [--days=3] [--only=production|boarding]
 */
class TransportJelafSync extends Command
{
    protected $signature = 'transport:jelaf-sync {--company= : company id (default: Turismo Cautivo)} {--days=3 : Producción por bus: days back, today included}
        {--only= : production | boarding}';

    protected $description = 'Download and import the Jelaf / AuraSoft reports of the last days (Turismo Cautivo).';

    public function handle(): int
    {
        $cid = (int) ($this->option('company') ?: JelafSync::companyId());
        $logFile = storage_path('logs/jelaf-sync-'.now()->format('Y-m-d').'.log');
        $say = function (string $msg) use ($logFile) {
            $line = now()->format('H:i:s').' '.$msg;
            $this->line($line);
            file_put_contents($logFile, $line.PHP_EOL, FILE_APPEND);
        };
        if (! $cid) {
            $say('FAILED: Turismo Cautivo not found.');

            return self::FAILURE;
        }
        $days = max(1, min(31, (int) $this->option('days')));
        $plan = [
            'production' => [now()->subDays($days - 1)->toDateString(), now()->toDateString()],
            'boarding' => [now()->subDay()->toDateString(), now()->toDateString()],
        ];
        $only = $this->option('only');
        $failed = false;
        foreach ($plan as $report => [$from, $to]) {
            if ($only && $only !== $report) {
                continue;
            }
            $say("start $report $from .. $to");
            $run = JelafSync::run($cid, $report, $from, $to, 'night', null, $say);
            $say("$report: {$run->status}, {$run->files} file(s), {$run->rows} rows".($run->message ? " -- {$run->message}" : ''));
            $failed = $failed || $run->status === 'failed';
            sleep(5);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
