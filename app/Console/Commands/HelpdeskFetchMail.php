<?php

namespace App\Console\Commands;

use App\Models\HelpdeskSetting;
use App\Services\Helpdesk\HelpdeskMailFetcher;
use Illuminate\Console\Command;

/** Imports new emails from every company's helpdesk mailbox (scheduled every 5 minutes). */
class HelpdeskFetchMail extends Command
{
    protected $signature = 'helpdesk:fetch-mail {--company= : only this company id}';

    protected $description = 'Import new emails from the helpdesk mailboxes into tickets';

    public function handle(): int
    {
        $q = HelpdeskSetting::where('imap_enabled', true)->whereNotNull('imap_host');
        if ($this->option('company')) {
            $q->where('company_id', (int) $this->option('company'));
        }
        foreach ($q->get() as $s) {
            if (! \App\Support\ModuleFlags::enabled((int) $s->company_id, 'helpdesk')) {
                continue; // Helpdesk switched off in Business Modules
            }
            try {
                $r = HelpdeskMailFetcher::fetch($s);
                $this->info("company {$s->company_id}: {$r['imported']} new ticket(s), {$r['replies']} repl(ies), {$r['skipped']} skipped");
            } catch (\Throwable $e) {
                $s->forceFill(['last_polled_at' => now(), 'last_error' => 'Reading: '.mb_substr($e->getMessage(), 0, 500)])->save();
                $this->error("company {$s->company_id}: ".$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
