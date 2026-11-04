<?php

namespace App\Console\Commands;

use App\Services\TransportParcelAssign;
use Illuminate\Console\Command;

/** Parcels waiting for a bus get the first one with room (TransportParcelAssign). Sends nothing. */
class TransportAssignParcels extends Command
{
    protected $signature = 'transport:assign-parcels';

    protected $description = 'Put parcels waiting for a bus on the first one with room';

    public function handle(): int
    {
        $this->info('Parcels assigned: '.TransportParcelAssign::assignPending());

        return self::SUCCESS;
    }
}
