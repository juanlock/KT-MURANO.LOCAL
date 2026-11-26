<?php

namespace App\Console\Commands;

use App\Support\AuraSoftImport;
use App\Support\JelafClient;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Nightly import of Turismo Cautivo's Jelaf / AuraSoft reports (2026-11-26: "que cada noche a las 10 pm Murano importe los
 * Excel que necesita"; tested first on Optimum as a Windows scheduled task, then the production scheduler). Downloads the
 * "Reporte producción por bus" (resumido F1, every company) of the last --days days, today included, and imports it with
 * the same importer as the upload button (AuraSoftImport: a departure already there is updated, never duplicated).
 * Every run is written to storage/logs/jelaf-sync-YYYY-MM-DD.log.
 *
 *   php artisan transport:jelaf-sync [--company=] [--days=3] [--dry]
 */
class TransportJelafSync extends Command
{
    protected $signature = 'transport:jelaf-sync {--company= : company id (default: Turismo Cautivo)} {--days=3 : days back, today included} {--dry : download only, no import}';

    protected $description = 'Download the Jelaf / AuraSoft production report of the last days and import it (Turismo Cautivo).';

    public function handle(): int
    {
        $cid = (int) ($this->option('company') ?: DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id'));
        $days = max(1, min(31, (int) $this->option('days')));
        $to = now()->toDateString();
        $from = now()->subDays($days - 1)->toDateString();
        $logFile = storage_path('logs/jelaf-sync-'.now()->format('Y-m-d').'.log');
        $log = function (string $msg) use ($logFile) {
            $line = now()->format('H:i:s').' '.$msg;
            $this->line($line);
            file_put_contents($logFile, $line.PHP_EOL, FILE_APPEND);
        };
        if (! $cid) {
            $log('FAILED: Turismo Cautivo not found.');

            return self::FAILURE;
        }
        $log("start: company $cid, Producción por bus $from .. $to".($this->option('dry') ? ' (dry)' : ''));
        try {
            $client = JelafClient::fromEnvFile();
            $client->login();
            // Every file downloaded from Jelaf is kept (user, 2026-11-26: "un record ordenado de archivos que provienen de Jelaf"):
            // KT-TOOLS/files/turismo/JELAF_INTEGRACION/01 Produccion por Bus/Nocturno on the PC (travels with the KT-TOOLS sync),
            // storage/app/jelaf/archive where there is no KT-TOOLS (the server). The import works on a copy.
            $dir = is_dir(base_path('KT-TOOLS/files/turismo/JELAF_INTEGRACION'))
                ? base_path('KT-TOOLS/files/turismo/JELAF_INTEGRACION/01 Produccion por Bus/Nocturno')
                : storage_path('app/jelaf/archive');
            $file = $dir.'/ProduccionParaBus '.$from.'_'.$to.' resumido F1 (bajado '.now()->format('Y-m-d H-i').').xlsx';
            $bytes = $client->productionPerBus($from, $to, $file);
            if (! $bytes) {
                $log('nothing to import (Jelaf: no data).');

                return self::SUCCESS;
            }
            $log("downloaded $bytes bytes");
            if ($this->option('dry')) {
                $log("dry run: kept $file");

                return self::SUCCESS;
            }
            $copy = storage_path('app/jelaf/'.basename($file));
            @mkdir(dirname($copy), 0775, true);
            copy($file, $copy);
            $upload = new UploadedFile($copy, basename($file), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
            $imp = AuraSoftImport::run($cid, $upload, null);
            $log(sprintf('imported #%s: %s rows (new %s, changed %s, same %s, skipped %s); kept %s', $imp->id ?? '?', $imp->rows_total ?? '?', $imp->rows_new ?? '?',
                $imp->rows_updated ?? '?', $imp->rows_same ?? '?', $imp->rows_skipped ?? '?', $file));
            @unlink($copy);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $log('FAILED: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }
    }
}
