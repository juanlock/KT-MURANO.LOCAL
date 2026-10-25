<?php

namespace App\Console\Commands;

use App\Models\HmsMedicationDose;
use Illuminate\Console\Command;

/**
 * Every night (user's rule, 2026-10-25): the doses of past days that no nurse
 * recorded become "missed" (not given) -- they can't be recorded afterwards.
 */
class HospitalMarkMissedDoses extends Command
{
    protected $signature = 'hospital:mark-missed-doses';

    protected $description = 'Mark past days\' unrecorded medication doses as missed';

    public function handle(): int
    {
        $this->info('Doses marked as missed: '.HmsMedicationDose::markMissed());

        return self::SUCCESS;
    }
}
