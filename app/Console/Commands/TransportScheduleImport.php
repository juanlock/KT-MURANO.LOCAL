<?php

namespace App\Console\Commands;

use App\Services\TransportSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Programación de buses (2026-11-26): import Operaciones' workbooks from the command line (the screen's "Importar Excel" does
 * the same): the base timetable ("Operaciones - Daniel.xlsx", sheet Salidas) or a season grid (DICIEMBRE 2025, FIESTAS PATRIAS
 * 2026). A season whose file was already imported is skipped (same file name), so it is safe to run twice.
 *
 *   php artisan transport:schedule-import {file} --kind=base|season [--company=] [--year=] [--month=] [--name=]
 */
class TransportScheduleImport extends Command
{
    protected $signature = 'transport:schedule-import {file} {--kind=season} {--company=} {--year=} {--month=} {--name=} {--as= : original file name}';

    protected $description = 'Import a bus programming workbook (base timetable or season) into Programación de buses.';

    public function handle(): int
    {
        $cid = (int) ($this->option('company') ?: DB::table('companies')->where('name', 'like', 'Turismo Cautivo%')->whereNull('deleted_at')->value('id'));
        $file = $this->argument('file');
        $name = $this->option('as') ?: basename($file);
        if (! $cid || ! is_file($file)) {
            $this->error(! $cid ? 'Company not found.' : 'File not found.');

            return self::FAILURE;
        }
        $kind = $this->option('kind') === 'base' ? 'base' : 'season';
        if ($kind === 'season' && DB::table('transport_schedule_plans')->where('company_id', $cid)->whereNull('deleted_at')->where('source_file', $name)->exists()) {
            $this->line("already imported: $name");

            return self::SUCCESS;
        }
        $r = $kind === 'base'
            ? TransportSchedule::importBase($cid, $file, $name, 0)
            : TransportSchedule::importSeason($cid, $file, $name, 0, $this->option('year') ? (int) $this->option('year') : null,
                $this->option('month') ? (int) $this->option('month') : null, $this->option('name') ?: null);
        $this->info("$name: ".json_encode($r));

        return self::SUCCESS;
    }
}
