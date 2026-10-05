<?php

namespace App\Console\Commands;

use App\Models\OfficeEmail;
use App\Services\EmailOffice\ZellePayment;
use App\Services\EmailOffice\ZelleSale;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Confirm, in bulk, the Zelle emails a rule prepared:
 *  - deposits received (link_role 'sale_draft')    -> one paid sale each;
 *  - payments sent    (link_role 'payment_draft') -> one expense each (only
 *    payees already mapped in email_office_payees; the rest are listed).
 * Safe to run again: a recorded email or a known confirmation number is never
 * recorded twice.
 *
 *   php artisan email-office:zelle-sales 3 [--user=4] [--only=sales|payments] [--dry]
 */
class EmailOfficeZelleSales extends Command
{
    protected $signature = 'email-office:zelle-sales {company : company id} {--user= : user recorded as the creator (default: the company\'s first Admin)} {--only= : sales or payments} {--dry : only show what would be done}';

    protected $description = 'Email Office: record the Zelle emails waiting for Confirm (deposits -> paid sales, payments sent -> expenses)';

    public function handle(): int
    {
        $cid = (int) $this->argument('company');
        $userId = (int) ($this->option('user') ?: DB::table('users')->where('company_id', $cid)->whereRaw('LOWER(type) = ?', ['admin'])->orderBy('id')->value('id'));
        if (! $userId) {
            $this->error('No user to record them as (use --user=).');

            return self::FAILURE;
        }
        $only = $this->option('only');
        $failed = 0;
        if ($only !== 'payments') {
            $failed += $this->sales($cid, $userId);
        }
        if ($only !== 'sales') {
            $failed += $this->payments($cid, $userId);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function drafts(int $cid, string $role)
    {
        return OfficeEmail::where('company_id', $cid)->where('direction', 'in')->where('link_role', $role)
            ->whereNull('linked_type')->orderBy('sent_at')->orderBy('id')->get();
    }

    private function sales(int $cid, int $userId): int
    {
        $emails = $this->drafts($cid, 'sale_draft');
        $this->info($emails->count().' Zelle deposit email(s) waiting.');
        $made = $linked = $clients = $failed = 0;
        $total = 0.0;
        foreach ($emails as $e) {
            $p = ZelleSale::parse($e->body_text);
            if ($this->option('dry')) {
                $this->line(sprintf('%s  %10s  %s  #%s', $e->sent_at, number_format($p['amount'] ?? 0, 2), $p['payer'] ?? '?', $p['confirmation'] ?? '?'));

                continue;
            }
            try {
                $r = ZelleSale::confirm($e, $userId);
                $r['created'] ? $made++ : $linked++;
                $clients += $r['client_created'] ? 1 : 0;
                $total += $r['created'] ? $p['amount'] : 0;
            } catch (\Throwable $ex) {
                $failed++;
                $this->warn("Email #{$e->id}: ".$ex->getMessage());
            }
        }
        if (! $this->option('dry') && $emails->count()) {
            $this->info("Sales created: {$made} (\$".number_format($total, 2)."), linked to an existing sale: {$linked}, new customers: {$clients}, failed: {$failed}.");
        }

        return $failed;
    }

    private function payments(int $cid, int $userId): int
    {
        $emails = $this->drafts($cid, 'payment_draft');
        $this->info($emails->count().' Zelle payment-sent email(s) waiting.');
        $made = $linked = $unmapped = $failed = 0;
        $total = 0.0;
        $who = [];
        foreach ($emails as $e) {
            $p = ZellePayment::parse($e->body_text);
            if (! $p || ! ZellePayment::mapping($cid, $p['payee_key'])) {
                $unmapped++;
                $who[$p['payee'] ?? '?'] = true;

                continue;
            }
            if ($this->option('dry')) {
                $this->line(sprintf('%s  %10s  %s  #%s', $e->sent_at, number_format($p['amount'], 2), $p['payee'], $p['confirmation'] ?? '?'));

                continue;
            }
            try {
                $r = ZellePayment::confirm($e, $userId);
                $r['created'] ? $made++ : $linked++;
                $total += $r['created'] ? $p['amount'] : 0;
            } catch (\Throwable $ex) {
                $failed++;
                $this->warn("Email #{$e->id}: ".$ex->getMessage());
            }
        }
        if (! $this->option('dry') && $emails->count()) {
            $this->info("Expenses created: {$made} (\$".number_format($total, 2)."), linked to an existing expense: {$linked}, failed: {$failed}.");
        }
        if ($unmapped) {
            $this->warn("{$unmapped} left for Confirm (payee not mapped yet): ".implode(', ', array_keys($who)));
        }

        return $failed;
    }
}
