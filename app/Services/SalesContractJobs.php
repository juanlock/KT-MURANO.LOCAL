<?php

namespace App\Services;

use App\Http\Controllers\ServiceJobController;
use App\Models\Client;
use App\Models\SalesContract;
use App\Models\ServiceJob;
use App\Models\ServiceTechnician;
use App\Models\Setting;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sales Contracts -> recurring Service Jobs (visits). A contract with
 * jobs_enabled gets one service job per visit, on its own schedule (the same
 * rules as the invoice schedule -- SalesContractBilling::nextDate()), created
 * jobs_lead_days before the visit so it's already on the technicians' list.
 * The job: the contract's customer and location, its technician, the visit
 * title (placeholders filled per visit), status Pending, scheduled on the
 * visit date at jobs_time. No lines and no price: the contract's invoices
 * bill the work, so a visit never bills twice.
 *
 * Runs from the daily `sales-contracts:invoice` command and right after a
 * contract is saved. Idempotent per visit (sales_contract_id + occurrence).
 */
class SalesContractJobs
{
    /** Visits already created for the contract (deleted ones count: a removed visit isn't recreated). */
    public static function createdCount(SalesContract $contract): int
    {
        return (int) DB::table('service_jobs')->where('sales_contract_id', $contract->id)->count();
    }

    /**
     * Create every visit of the contract that's due (visit date <= today +
     * lead days), max $max per call.
     *
     * @return array<ServiceJob> jobs created
     */
    public function generateFor(SalesContract $contract, ?Carbon $today = null, int $max = 12, ?int $userId = null): array
    {
        $today = ($today ?: now())->copy()->startOfDay();
        $created = [];
        for ($i = 0; $i < $max; $i++) {
            $contract->refresh();
            if (! $contract->jobs_enabled || $contract->status !== 'active' || ! $contract->next_job_date) {
                break;
            }
            $visit = Carbon::parse($contract->next_job_date)->startOfDay();
            if ($visit->gt($today->copy()->addDays(max(0, (int) $contract->jobs_lead_days)))) {
                break;
            }
            if ($contract->end_date && $visit->gt(Carbon::parse($contract->end_date))) {
                $contract->forceFill(['next_job_date' => null])->save();
                break;
            }
            $job = $this->createVisit($contract, $visit, $userId);
            if (! $job) {
                break;
            }
            $created[] = $job;
        }

        return $created;
    }

    /** Daily run: every active contract with jobs on. */
    public function generateDue(?Carbon $today = null): array
    {
        $done = [];
        $limit = ($today ?: now())->copy()->addDays(31)->toDateString();
        $contracts = SalesContract::whereNull('deleted_at')->where('status', 'active')->where('jobs_enabled', true)
            ->whereNotNull('next_job_date')->where('next_job_date', '<=', $limit)->get();
        foreach ($contracts as $contract) {
            try {
                foreach ($this->generateFor($contract, $today) as $job) {
                    $done[] = ['contract' => $contract->contract_number, 'job' => $job->Ref, 'date' => substr((string) $job->scheduled_date, 0, 10)];
                }
            } catch (\Throwable $e) {
                Log::warning('Sales contract service job failed: '.$e->getMessage(), ['contract_id' => $contract->id]);
            }
        }

        return $done;
    }

    private function createVisit(SalesContract $contract, Carbon $visit, ?int $userId): ?ServiceJob
    {
        $companyId = (int) $contract->company_id;
        $client = Client::whereNull('deleted_at')->find($contract->client_id);
        if (! $client || (int) $client->company_id !== $companyId) {
            return null;
        }
        $warehouseId = $this->warehouseFor($contract);
        $already = self::createdCount($contract);
        $count = $contract->jobs_count ?: null;
        if ($count && $already >= $count) {
            $contract->forceFill(['next_job_date' => null])->save();

            return null;
        }
        $number = $already + 1;
        $frequency = (string) ($contract->jobs_frequency ?: 'weekly');
        $interval = (int) ($contract->jobs_interval ?: 1);
        $mode = (string) ($contract->jobs_day_mode ?: 'same');
        $day = $contract->jobs_day ? (int) $contract->jobs_day : null;
        $next = SalesContractBilling::nextDate($visit, $frequency, $interval, $mode, $day);
        [$periodStart, $periodEnd] = SalesContractBilling::period($visit, $frequency, $interval, $mode, $day);
        $technician = $contract->jobs_technician_id
            && ServiceTechnician::whereKey($contract->jobs_technician_id)->where('company_id', $companyId)->whereNull('deleted_at')->exists()
            ? (int) $contract->jobs_technician_id : null;
        // No visit time = an all-day task on the visit day.
        $time = preg_match('/^\d{2}:\d{2}$/', (string) $contract->jobs_time) ? $contract->jobs_time : null;

        return DB::transaction(function () use ($contract, $client, $warehouseId, $visit, $next, $number, $count, $periodStart, $periodEnd, $technician, $time) {
            $locked = SalesContract::whereKey($contract->id)->lockForUpdate()->first();
            if ($locked->next_job_date !== $contract->next_job_date
                || DB::table('service_jobs')->where('sales_contract_id', $locked->id)->where('contract_occurrence', $number)->exists()) {
                return null; // another run made this visit
            }
            $title = SalesContractBilling::renderNote($locked->jobs_service_item, $periodStart, $periodEnd, $number, $count, $visit)
                ?: ($locked->subject ?: 'Contract visit');

            $job = ServiceJob::create([
                'Ref' => app(ServiceJobController::class)->getNumberOrder(),
                'sales_contract_id' => $locked->id,
                'contract_occurrence' => $number,
                'client_id' => $client->id,
                'technician_id' => $technician,
                'warehouse_id' => $warehouseId,
                'service_item' => mb_substr($title, 0, 191),
                'job_type' => $locked->jobs_job_type ?: null,
                'status' => 'pending',
                'scheduled_date' => ($time ? $visit->copy()->setTimeFromTimeString($time) : $visit->copy()->startOfDay())->toDateTimeString(),
                'all_day' => ! $time,
                'notes' => 'Sales contract '.$locked->contract_number.' - visit '.$number.($count ? ' of '.$count : ''),
            ]);

            $isLast = $count && $number >= $count;
            $locked->forceFill(['next_job_date' => $isLast ? null : $next->toDateString()])->save();

            return $job;
        });
    }

    /** The contract's location, else the company default, else its first location. */
    private function warehouseFor(SalesContract $contract): ?int
    {
        $companyId = (int) $contract->company_id;
        $ok = fn ($id) => $id && Warehouse::whereKey($id)->where('company_id', $companyId)->whereNull('deleted_at')->exists();
        if ($ok($contract->warehouse_id)) {
            return (int) $contract->warehouse_id;
        }
        $default = Setting::forCompany($companyId)->warehouse_id ?? null;

        return $ok($default) ? (int) $default
            : Warehouse::where('company_id', $companyId)->whereNull('deleted_at')->orderBy('id')->value('id');
    }
}
