<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Permission;
use App\Models\User;
use App\Notifications\AssetValidationDueNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckAssetValidationDue extends Command
{
    protected $signature = 'assets:check-validation-due';

    protected $description = 'Notify admins when asset next_validation is within 5 working days or overdue';

    /**
     * Add working days to a date (excluding Saturday and Sunday).
     *
     * @param  \Carbon\Carbon  $date
     * @param  int  $days
     * @return \Carbon\Carbon
     */
    protected function addWorkingDays(Carbon $date, int $days): Carbon
    {
        $d = $date->copy();
        $added = 0;
        while ($added < $days) {
            $d->addDay();
            if (! $d->isWeekend()) {
                $added++;
            }
        }

        return $d;
    }

    /**
     * Check if a date is within the next N working days from today (inclusive) or in the past.
     *
     * @param  \Carbon\Carbon  $nextValidation
     * @param  int  $workingDays
     * @return bool
     */
    protected function isDueWithinWorkingDays(Carbon $nextValidation, int $workingDays = 5): bool
    {
        $today = Carbon::today();
        $limit = $this->addWorkingDays($today, $workingDays);
        $nextValidation->startOfDay();

        return $nextValidation->lte($limit);
    }

    public function handle()
    {
        $today = Carbon::today();
        $limit = $this->addWorkingDays($today, 5);

        $assets = Asset::whereNull('deleted_at')
            ->whereNotNull('next_validation')
            ->get()
            ->filter(function (Asset $asset) {
                return $this->isDueWithinWorkingDays($asset->next_validation->copy(), 5);
            });

        if ($assets->isEmpty()) {
            $this->info('No assets due for validation within the next 5 working days.');

            return 0;
        }

        // Demo assets have no real tool behind them: nobody is told about them.
        $demo = DB::table('demo_records')->where('record_type', 'assets')->pluck('record_id')->map(fn ($id) => (int) $id)->flip();
        $assets = $assets->reject(fn (Asset $asset) => isset($demo[(int) $asset->id]));
        if ($assets->isEmpty()) {
            $this->info('No real assets due for validation within the next 5 working days.');

            return 0;
        }

        $permission = Permission::where('name', 'assets')->first();
        if (! $permission) {
            $this->warn('Permission "assets" not found. No notifications sent.');

            return 0;
        }

        $roleIds = $permission->roles()->pluck('roles.id')->toArray();
        if (empty($roleIds)) {
            $this->warn('No roles have "assets" permission. No notifications sent.');

            return 0;
        }

        // Only the asset's own company, active logins, once per asset and validation date: this runs every
        // day, and an overdue asset must not mail everyone daily. Multicompany: never another company's users.
        $sent = 0;
        foreach ($assets as $asset) {
            $companyId = optional($asset->warehouse)->company_id;
            if (! $companyId) {
                continue;
            }
            $date = $asset->next_validation->format('Y-m-d');
            $users = User::whereNull('deleted_at')
                ->where('statut', 1)
                ->where('company_id', $companyId)
                ->whereHas('roles', function ($q) use ($roleIds) {
                    $q->whereIn('roles.id', $roleIds);
                })
                ->get()
                ->unique('id');

            foreach ($users as $user) {
                $told = $user->notifications()
                    ->where('type', AssetValidationDueNotification::class)
                    ->where('data->asset_id', (int) $asset->id)
                    ->where('data->next_validation', $date)
                    ->exists();
                if (! $told) {
                    $user->notify(new AssetValidationDueNotification($asset));
                    $sent++;
                }
            }
        }

        $this->info(sprintf('Sent %d notification(s) for %d asset(s) due for validation.', $sent, $assets->count()));

        return 0;
    }
}
