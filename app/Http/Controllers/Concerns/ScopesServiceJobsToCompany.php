<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Client;
use App\Models\ServiceJob;

/**
 * Multicompany for Service Jobs. A job has no company_id: it belongs to the
 * company of its location (warehouse), or -- with no location -- of its
 * customer. Technicians and checklists have their own company_id.
 * Same rule as ScopesReportsToCompany: an Admin is locked to their company,
 * a SuperAdmin sees the company picked in the header (all when none).
 */
trait ScopesServiceJobsToCompany
{
    use ScopesReportsToCompany;

    /** Restrict a service_jobs query (table or alias) to the company's jobs. */
    protected function scopeServiceJobs($query, string $table = 'service_jobs')
    {
        return $query->when(! $this->reportAllWarehouses(), fn ($q) => $q->where(fn ($w) => $w
            ->whereIn("{$table}.warehouse_id", $this->reportWarehouseIdList())
            ->orWhere(fn ($x) => $x->whereNull("{$table}.warehouse_id")
                ->whereIn("{$table}.client_id", $this->rc(Client::query(), 'clients.company_id')->select('id')))));
    }

    /** ServiceJob query limited to the company's jobs. */
    protected function scopedJobs()
    {
        return $this->scopeServiceJobs(ServiceJob::query());
    }

    /** Company that new technicians / checklists are created for. */
    protected function serviceCompanyId(): ?int
    {
        return $this->reportCompanyId() ?: (int) optional(request()->user('api'))->company_id ?: null;
    }
}
