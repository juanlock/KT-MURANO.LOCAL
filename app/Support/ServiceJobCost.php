<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Service job costing: what a job cost the company.
 *  - labor_cost = work_hours x the operator's hourly rate (employees.hourly_rate
 *    of the technician's linked employee; commission staff = 0 here, they are
 *    paid on sales);
 *  - asset_cost = equipment used (service_job_assets: hours x the asset's hourly_cost).
 */
class ServiceJobCost
{
    /** The operator's hourly rate for a job (0 when not hourly / not linked). */
    public static function operatorRate(?int $technicianId): float
    {
        if (! $technicianId) {
            return 0.0;
        }
        $emp = DB::table('service_technicians as t')->join('employees as e', 'e.id', '=', 't.employee_id')
            ->where('t.id', $technicianId)->first(['e.pay_type', 'e.hourly_rate']);

        return $emp && $emp->pay_type === 'hourly' ? (float) $emp->hourly_rate : 0.0;
    }

    /**
     * Equipment used on a job = exactly these rows ([{asset_id, hours}]); the
     * asset's current hourly cost is kept on each line. Only the company's assets.
     */
    public static function syncAssets(int $jobId, array $rows, ?int $companyId): void
    {
        if (! Schema::hasTable('service_job_assets')) {
            return;
        }
        DB::table('service_job_assets')->where('service_job_id', $jobId)->delete();
        foreach ($rows as $r) {
            $assetId = (int) ($r['asset_id'] ?? 0);
            $hours = max(0, round((float) ($r['hours'] ?? 0), 2));
            if (! $assetId) {
                continue;
            }
            $asset = DB::table('assets as a')->leftJoin('warehouses as w', 'w.id', '=', 'a.warehouse_id')
                ->where('a.id', $assetId)->whereNull('a.deleted_at')
                ->when($companyId, fn ($q) => $q->where('w.company_id', $companyId))
                ->first(['a.id', 'a.hourly_cost']);
            if (! $asset) {
                continue;
            }
            $rate = (float) ($asset->hourly_cost ?? 0);
            DB::table('service_job_assets')->insert([
                'service_job_id' => $jobId, 'asset_id' => $assetId, 'hours' => $hours,
                'hourly_cost' => $rate, 'cost' => round($hours * $rate, 2),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** "All operators" crew job: the summed hourly rates of the company's active operators. */
    public static function crewRate(?int $companyId): float
    {
        if (! $companyId) {
            return 0.0;
        }

        return (float) DB::table('service_technicians as t')->join('employees as e', 'e.id', '=', 't.employee_id')
            ->where('t.company_id', $companyId)->whereNull('t.deleted_at')->where('t.is_active', 1)
            ->whereNull('e.deleted_at')->where('e.pay_type', 'hourly')->sum('e.hourly_rate');
    }

    /** The job's labor rate: its operator's, or the whole crew's for an "All operators" job. */
    public static function jobRate(int $jobId): float
    {
        $job = DB::table('service_jobs')->where('id', $jobId)->first(['technician_id', 'assign_all']);
        if (! $job) {
            return 0.0;
        }

        return ! empty($job->assign_all)
            ? self::crewRate(ServiceJobLinks::jobCompany($jobId))
            : self::operatorRate($job->technician_id ? (int) $job->technician_id : null);
    }

    /** Recompute and store labor_cost / asset_cost. Returns [labor, assets, total]. */
    public static function recalc(int $jobId): array
    {
        $job = DB::table('service_jobs')->where('id', $jobId)->first(['technician_id', 'work_hours']);
        if (! $job || ! Schema::hasColumn('service_jobs', 'labor_cost')) {
            return [0, 0, 0];
        }
        $labor = round((float) $job->work_hours * self::jobRate($jobId), 2);
        $assets = Schema::hasTable('service_job_assets')
            ? round((float) DB::table('service_job_assets')->where('service_job_id', $jobId)->sum('cost'), 2)
            : 0.0;
        DB::table('service_jobs')->where('id', $jobId)->update(['labor_cost' => $labor, 'asset_cost' => $assets]);

        return [$labor, $assets, round($labor + $assets, 2)];
    }

    /** Equipment lines of a job, for the forms / details. */
    public static function assetsOf(int $jobId): array
    {
        if (! Schema::hasTable('service_job_assets')) {
            return [];
        }

        return DB::table('service_job_assets as l')->join('assets as a', 'a.id', '=', 'l.asset_id')
            ->where('l.service_job_id', $jobId)->orderBy('l.id')
            ->get(['l.asset_id', 'a.name', 'a.tag', 'l.hours', 'l.hourly_cost', 'l.cost'])
            ->map(fn ($r) => (array) $r)->all();
    }
}
