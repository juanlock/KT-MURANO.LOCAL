<?php

namespace App\Console\Commands;

use App\Models\EmailOfficeSetting;
use App\Services\EmailOffice\EmailOfficeFetcher;
use App\Support\ModuleFlags;
use Illuminate\Console\Command;

/**
 * Email Office: import each company's mailbox. Scheduled every minute; each
 * company is only read when its own "check every" interval has passed
 * (--force reads them all now).
 */
class EmailOfficeFetch extends Command
{
    protected $signature = 'email-office:fetch {--company= : one company id} {--force : ignore the interval}';

    protected $description = 'Import received and sent emails from each company mailbox (Email Office)';

    public function handle(): int
    {
        // "Import all emails" in progress: a few batches per run (the
        // Settings page also drives it while open). Runs even with automatic
        // import off -- the user started it on purpose.
        foreach (EmailOfficeSetting::whereNotNull('full_import')->get() as $s) {
            if (($s->full_import['status'] ?? null) !== 'running' || ! ModuleFlags::enabled((int) $s->company_id, 'email_office')) {
                continue;
            }
            $st = EmailOfficeFetcher::fullStep($s, 6, 45);
            $this->line("company {$s->company_id}: import all -- {$st['read']}/{$st['total']} read, {$st['imported']} new".(! empty($st['error']) ? ' ('.$st['error'].')' : ''));
        }

        $settings = EmailOfficeSetting::where('enabled', true)
            ->when($this->option('company'), fn ($q) => $q->where('company_id', (int) $this->option('company')))
            ->get();
        foreach ($settings as $s) {
            if (! ModuleFlags::enabled((int) $s->company_id, 'email_office') || (! $this->option('force') && ! $s->isDue())) {
                continue;
            }
            try {
                $st = EmailOfficeFetcher::fetch($s);
                $this->line("company {$s->company_id}: {$st['received']} received, {$st['sent']} sent, {$st['skipped']} already there");
            } catch (\Throwable $e) {
                $this->warn("company {$s->company_id}: ".$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
