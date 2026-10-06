<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Service jobs <-> invoices (service_job_sales), per company:
 *  - on only with Defaults -> Sales -> "Service jobs on sales" and the Service
 *    module (default off; Ruba on);
 *  - a link joins a job and a sale of the SAME company and the SAME customer.
 */
class ServiceJobLinks
{
    public static function enabled(?int $companyId): bool
    {
        if (! $companyId || ! Schema::hasTable('service_job_sales')) {
            return false;
        }

        return ModuleFlags::enabled($companyId, 'service') && (bool) (Setting::forCompany($companyId)->sale_service_jobs_enabled ?? false);
    }

    /** Company of a customer. */
    public static function clientCompany(int $clientId): ?int
    {
        $cid = DB::table('clients')->where('id', $clientId)->value('company_id');

        return $cid ? (int) $cid : null;
    }

    /** Company of a service job (its location's, else its customer's). */
    public static function jobCompany(int $jobId): ?int
    {
        $job = DB::table('service_jobs')->where('id', $jobId)->first(['warehouse_id', 'client_id']);
        if (! $job) {
            return null;
        }
        $cid = $job->warehouse_id ? DB::table('warehouses')->where('id', $job->warehouse_id)->value('company_id') : null;

        return $cid ? (int) $cid : ($job->client_id ? self::clientCompany((int) $job->client_id) : null);
    }

    /**
     * The customer's service jobs (not cancelled), for the sale form picker:
     * status, notes and the OTHER invoices each job is already on (to warn
     * before billing it twice). $exceptSaleId = the sale being edited.
     */
    public static function clientJobs(int $companyId, int $clientId, ?int $exceptSaleId = null): array
    {
        if (self::clientCompany($clientId) !== $companyId) {
            return [];
        }

        // A customer belongs to one company, so their jobs are that company's.
        $jobs = DB::table('service_jobs as j')
            ->where('j.client_id', $clientId)->whereNull('j.deleted_at')
            ->where('j.status', '!=', 'cancelled')->orderByDesc('j.id')->limit(200)
            ->get(['j.id', 'j.Ref', 'j.service_item', 'j.status', 'j.scheduled_date', 'j.notes', 'j.sale_id']);
        $ids = $jobs->pluck('id')->all();
        // Invoices per job: the pivot plus the legacy service_jobs.sale_id.
        $pairs = DB::table('service_job_sales')->whereIn('service_job_id', $ids ?: [0])->get(['service_job_id', 'sale_id'])
            ->map(fn ($r) => [(int) $r->service_job_id, (int) $r->sale_id])->all();
        foreach ($jobs as $j) {
            if ($j->sale_id) {
                $pairs[] = [(int) $j->id, (int) $j->sale_id];
            }
        }
        $refs = DB::table('sales')->whereIn('id', array_unique(array_column($pairs, 1)) ?: [0])->whereNull('deleted_at')->pluck('Ref', 'id');
        $onSales = [];
        foreach ($pairs as [$jid, $sid]) {
            if ($sid !== $exceptSaleId && isset($refs[$sid])) {
                $onSales[$jid][$sid] = $refs[$sid];
            }
        }

        return $jobs->map(fn ($j) => [
            'id' => (int) $j->id,
            'ref' => $j->Ref,
            'title' => $j->service_item,
            'status' => $j->status,
            'notes' => $j->notes,
            'label' => trim($j->Ref.' · '.($j->service_item ?: 'Job').' · '.$j->status.($j->scheduled_date ? ' · '.substr($j->scheduled_date, 0, 10) : '')),
            'other_invoices' => array_values($onSales[(int) $j->id] ?? []),
        ])->all();
    }

    /** The customer's invoices, for the service job form picker. */
    public static function clientInvoices(int $companyId, int $clientId): array
    {
        return DB::table('sales')->where('company_id', $companyId)->where('client_id', $clientId)->whereNull('deleted_at')
            ->orderByDesc('date')->orderByDesc('id')->limit(200)
            ->get(['id', 'Ref', 'date', 'GrandTotal', 'payment_statut'])
            ->map(fn ($s) => ['id' => (int) $s->id, 'label' => $s->Ref.' · '.$s->date.' · '.number_format((float) $s->GrandTotal, 2).' · '.$s->payment_statut])
            ->all();
    }

    /** Job ids linked to a sale. */
    public static function jobsOfSale(int $saleId): array
    {
        return DB::table('service_job_sales')->where('sale_id', $saleId)->pluck('service_job_id')->map(fn ($v) => (int) $v)->all();
    }

    /** Sale ids linked to a job. */
    public static function salesOfJob(int $jobId): array
    {
        return DB::table('service_job_sales')->where('service_job_id', $jobId)->pluck('sale_id')->map(fn ($v) => (int) $v)->all();
    }

    /** Linked invoices of a job, for display ({id, Ref, date, GrandTotal, payment_statut}). */
    public static function invoicesOfJob(int $jobId): array
    {
        return DB::table('service_job_sales as l')->join('sales as s', 's.id', '=', 'l.sale_id')
            ->where('l.service_job_id', $jobId)->whereNull('s.deleted_at')->orderBy('s.date')
            ->get(['s.id', 's.Ref', 's.date', 's.GrandTotal', 's.paid_amount', 's.payment_statut'])
            ->map(fn ($s) => (array) $s)->all();
    }

    /** One link (e.g. a job created with a sale). */
    public static function link(int $jobId, int $saleId): void
    {
        DB::table('service_job_sales')->insertOrIgnore(['service_job_id' => $jobId, 'sale_id' => $saleId, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** A sale's linked jobs = exactly these (same company + customer only). */
    public static function syncForSale(int $saleId, array $jobIds): void
    {
        $sale = DB::table('sales')->where('id', $saleId)->first(['company_id', 'client_id']);
        if (! $sale) {
            return;
        }
        $valid = self::clientCompany((int) $sale->client_id) === (int) $sale->company_id
            ? DB::table('service_jobs')->whereIn('id', array_map('intval', $jobIds))->where('client_id', $sale->client_id)
                ->whereNull('deleted_at')->pluck('id')->map(fn ($v) => (int) $v)->all()
            : [];
        DB::table('service_job_sales')->where('sale_id', $saleId)->whereNotIn('service_job_id', $valid ?: [0])->delete();
        foreach ($valid as $jid) {
            self::link($jid, $saleId);
        }
    }

    /** A job's linked invoices = exactly these (same company + customer only). */
    public static function syncForJob(int $jobId, array $saleIds): void
    {
        $job = DB::table('service_jobs')->where('id', $jobId)->first(['client_id']);
        $cid = self::jobCompany($jobId);
        if (! $job || ! $cid) {
            return;
        }
        $valid = DB::table('sales')->whereIn('id', array_map('intval', $saleIds))->where('company_id', $cid)
            ->where('client_id', $job->client_id)->whereNull('deleted_at')->pluck('id')->map(fn ($v) => (int) $v)->all();
        DB::table('service_job_sales')->where('service_job_id', $jobId)->whereNotIn('sale_id', $valid ?: [0])->delete();
        foreach ($valid as $sid) {
            self::link($jobId, $sid);
        }
    }
}
