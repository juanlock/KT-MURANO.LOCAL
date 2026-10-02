<?php

namespace App\Console\Commands;

use App\Services\SalesContractBilling;
use Illuminate\Console\Command;

/** Daily: create the recurring invoices of active Sales Contracts whose date has come. */
class SalesContractsInvoice extends Command
{
    protected $signature = 'sales-contracts:invoice';

    protected $description = 'Generate due recurring invoices (Sales > Sales Contracts)';

    public function handle(SalesContractBilling $billing): int
    {
        $done = $billing->generateDue();
        foreach ($done as $d) {
            $this->line("{$d['contract']} -> {$d['sale']} ({$d['total']})");
        }
        $this->info(count($done).' invoice(s) created.');

        return self::SUCCESS;
    }
}
