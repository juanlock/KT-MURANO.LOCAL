<?php

namespace App\Console\Commands;

use App\Support\JelafClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Jelaf "Control de Embarque" Resumen + Detalle, one file per travel day and report (2026-11-26), by plain HTTP.
 * Kept in KT-TOOLS/files/turismo/JELAF_INTEGRACION/06 Control de Embarque on a PC, storage/app/jelaf/archive/embarque on the
 * server; a day already downloaded is skipped. One request at a time with a pause; 3 failures in a row stop the run.
 *
 *   php artisan transport:jelaf-boarding [from] [to] [--pause=5]     (default: yesterday)
 */
class TransportJelafBoarding extends Command
{
    protected $signature = 'transport:jelaf-boarding {from? : yyyy-mm-dd, default yesterday} {to? : yyyy-mm-dd, default = from} {--pause=5 : seconds between requests}';

    protected $description = 'Download Jelaf Control de Embarque (Resumen + Detalle) per travel day.';

    public function handle(): int
    {
        $from = $this->argument('from') ?: now()->subDay()->toDateString();
        $to = $this->argument('to') ?: $from;
        $pause = max(1, (int) $this->option('pause'));
        $base = is_dir(base_path('KT-TOOLS/files/turismo/JELAF_INTEGRACION'))
            ? base_path('KT-TOOLS/files/turismo/JELAF_INTEGRACION/06 Control de Embarque')
            : storage_path('app/jelaf/archive/embarque');
        $dirs = ['resumen' => [$base.'/Descargado_Jelaf_ControlEmbarqueResumen', 'ControlEmbarqueResumen_PuntoEmbarque'],
            'detalle' => [$base.'/Descargado_Jelaf_ControlEmbarqueDetalle', 'ControlEmbarqueDetalle_NombrePasajero']];
        $logFile = storage_path('logs/jelaf-boarding-'.now()->format('Y-m-d').'.log');
        $log = function (string $m) use ($logFile) {
            $line = now()->format('H:i:s').' '.$m;
            $this->line($line);
            file_put_contents($logFile, $line.PHP_EOL, FILE_APPEND);
        };
        $client = JelafClient::fromEnvFile();
        $client->login();
        $ok = $skip = $empty = $fails = 0;
        $log("start $from .. $to (pause {$pause}s)");
        for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
            $day = date('Y-m-d', $d);
            foreach ($dirs as $kind => [$dir, $prefix]) {
                $target = "$dir/$prefix-$day.xlsx";
                if (is_file($target)) {
                    $skip++;
                    continue;
                }
                try {
                    $bytes = $client->boarding($kind, $day, $target);
                    $bytes ? $ok++ : $empty++;
                    $fails = 0;
                    if (! $bytes) {
                        $log("$day $kind: no data");
                    }
                } catch (Throwable $e) {
                    $log("$day $kind FAILED: ".$e->getMessage());
                    if (++$fails >= 3) {
                        $log('3 failures in a row -- stop');

                        return self::FAILURE;
                    }
                    sleep(60);
                    $client->login();
                }
                sleep($pause);
            }
            if (date('d', $d) === '01' || $day === $to) {
                $log("up to $day: downloaded $ok, already there $skip, no data $empty");
            }
        }
        $log("done: downloaded $ok, already there $skip, no data $empty");

        return self::SUCCESS;
    }
}
