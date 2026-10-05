<?php

namespace App\Console\Commands;

use App\Services\RecurringExpenses;
use Illuminate\Console\Command;

/** Daily: create the recurring expenses whose date has come (Expenses -> Recurring). */
class ExpensesRecurring extends Command
{
    protected $signature = 'expenses:recurring {--company= : one company id}';

    protected $description = 'Create the recurring expenses (same amount every period) whose date has come';

    public function handle(): int
    {
        $n = RecurringExpenses::generateDue($this->option('company') ? (int) $this->option('company') : null);
        $this->info("Created {$n} expense".($n === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
