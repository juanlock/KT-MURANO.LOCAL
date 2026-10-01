<?php

namespace App\Console\Commands;

use App\Services\LinkedUserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gives every customer / supplier / employee that has no linked user one --
 * the same users row (type customer / supplier / employee + that company's
 * role) the create forms make. For records created before that rule, by
 * imports or by demo data. Demo records' users are tagged as demo, so
 * "Remove demo data" removes them too.
 *
 * Skipped on purpose:
 *  - each company's default customer (Walk-in) -- a counter, not a person;
 *  - customers waiting for confirmation: created by the online booking page
 *    with no confirmed/completed booking yet, or a web-store sign-up not
 *    approved yet (they get their account on confirmation, by design).
 *
 *   php artisan murano:link-users [--company=3] [--dry]
 */
class LinkMissingUsers extends Command
{
    protected $signature = 'murano:link-users {--company= : Only this company id} {--dry : Only count, change nothing}';

    protected $description = 'Create the missing linked users for customers, suppliers and employees';

    public function handle(): int
    {
        $only = $this->option('company') ? (int) $this->option('company') : null;
        $dry = (bool) $this->option('dry');
        $defaults = DB::table('settings')->whereNull('deleted_at')->whereNotNull('client_id')->pluck('client_id')->map(fn ($v) => (int) $v)->all();

        $sets = [
            'clients' => ['customer', 'Customer', fn ($r) => $r->name],
            'providers' => ['supplier', 'Supplier', fn ($r) => $r->name],
            'employees' => ['employee', 'Employee', fn ($r) => trim($r->firstname.' '.$r->lastname)],
        ];
        $summary = [];

        foreach ($sets as $table => [$type, $roleName, $nameOf]) {
            $rows = DB::table($table)->whereNull('deleted_at')->whereNull('user_id')
                ->when($only, fn ($q) => $q->where('company_id', $only))
                ->orderBy('id')->get();

            foreach ($rows as $r) {
                if (! $r->company_id) {
                    continue;
                }
                $skip = null;
                if ($table === 'clients') {
                    if (in_array((int) $r->id, $defaults, true) || strcasecmp(trim((string) $r->name), 'Walk-in Customer') === 0) {
                        $skip = 'default customer';
                    } elseif (str_contains((string) ($r->notes ?? ''), 'online booking page')
                        && ! DB::table('bookings')->where('customer_id', $r->id)->whereIn('status', ['confirmed', 'completed'])->whereNull('deleted_at')->exists()) {
                        $skip = 'online booking not confirmed yet';
                    } elseif (DB::table('ecommerce_clients')->where('client_id', $r->id)->whereNull('deleted_at')->where(function ($q) {
                        $q->where('status', '!=', 'approved')->orWhereNull('email_verified_at');
                    })->exists()) {
                        $skip = 'web store sign-up not approved yet';
                    }
                }
                $key = "{$table} co{$r->company_id}";
                $summary[$key] ??= ['linked' => 0, 'skipped' => 0];
                if ($skip) {
                    $summary[$key]['skipped']++;
                    $this->line("  skip {$table} #{$r->id} ({$skip})");
                    continue;
                }
                $summary[$key]['linked']++;
                if ($dry) {
                    continue;
                }

                DB::transaction(function () use ($table, $type, $roleName, $nameOf, $r) {
                    $userId = LinkedUserService::provision((int) $r->company_id, $type, $roleName, (string) ($nameOf($r) ?: ucfirst($type)), $r->email ?? null, $r->phone ?? null);
                    DB::table($table)->where('id', $r->id)->update(['user_id' => $userId]);
                    // Employee logins start disabled (nobody knows the random
                    // password) until an admin turns on Edit -> System access.
                    if ($table === 'employees') {
                        DB::table('users')->where('id', $userId)->update(['statut' => 0]);
                    }
                    // A demo record's user is demo too (removed with it).
                    $demo = DB::table('demo_records')->where('record_type', $table)->where('record_id', $r->id)->first(['company_id', 'module']);
                    if ($demo) {
                        DB::table('demo_records')->insert([
                            'company_id' => $demo->company_id, 'module' => $demo->module, 'record_type' => 'users',
                            'record_id' => $userId, 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                });
            }
        }

        foreach ($summary as $k => $s) {
            $this->info(sprintf('%-18s %s %d, skipped %d', $k, $dry ? 'would link' : 'linked', $s['linked'], $s['skipped']));
        }
        if (! $summary) {
            $this->info('Nothing to link.');
        }

        return self::SUCCESS;
    }
}
