<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\PaymentMethod;
use App\Models\product_warehouse;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\QuotationDetail;
use App\Models\ServiceJob;
use App\Models\ServiceJobChecklistItem;
use App\Models\ServiceJobItem;
use App\Models\ServiceJobPayment;
use App\Models\ServiceJobPhoto;
use App\Models\ServiceTechnician;
use App\Models\Setting;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Traits\CalculatesCogsAndAverageCost;
use Illuminate\Support\Facades\Auth;
use App\utils\helpers;
use ArPHP\I18N\Arabic;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PDF;

class ServiceJobController extends BaseController
{
    // Multicompany: a job belongs to its location's (else its customer's)
    // company; technicians / checklists have company_id -- see the trait.
    use \App\Http\Controllers\Concerns\ScopesServiceJobsToCompany;

    use CalculatesCogsAndAverageCost;

    // -------------- Get All Service Jobs ---------------\\
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', ServiceJob::class);

        $perPage = $request->limit ?: 10;
        $pageStart = (int) $request->get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?: 'id';
        $dir = strtolower($request->SortType ?: 'desc');
        if (! in_array($dir, ['asc', 'desc'], true)) {
            $dir = 'desc';
        }

        $sortableMap = [
            'id' => 'service_jobs.id',
            'Ref' => 'service_jobs.Ref',
            'scheduled_date' => 'service_jobs.scheduled_date',
            'status' => 'service_jobs.status',
            'job_type' => 'service_jobs.job_type',
            'payment_status' => 'service_jobs.payment_status',
            'total_amount' => 'service_jobs.total_amount',
        ];
        $order = $sortableMap[$order] ?? 'service_jobs.Ref';

        $query = ServiceJob::leftJoin('clients', 'clients.id', '=', 'service_jobs.client_id')
            // Multicompany: a job belongs to the company of its warehouse (or, without one, of its customer).
            ->when(! $this->reportAllWarehouses(), fn ($q) => $q->where(fn ($w) => $w->whereIn('service_jobs.warehouse_id', $this->reportWarehouseIdList())->orWhere(fn ($x) => $x->whereNull('service_jobs.warehouse_id')->whereIn('service_jobs.client_id', $this->rc(Client::query(), 'clients.company_id')->select('id')))))
            ->leftJoin('service_technicians', 'service_technicians.id', '=', 'service_jobs.technician_id')
            ->whereNull('service_jobs.deleted_at')
            ->select(
                'service_jobs.*',
                'clients.name as client_name',
                'service_technicians.name as technician_name'
            )
            ->where(function ($q) use ($request) {
                return $q->when($request->filled('search'), function ($q) use ($request) {
                    $s = $request->search;

                    // Qualified: the technicians join also carries a `notes`
                    // column, and an unqualified name makes MySQL reject the query.
                    return $q->where('service_jobs.service_item', 'LIKE', "%{$s}%")
                        ->orWhere('service_jobs.job_type', 'LIKE', "%{$s}%")
                        ->orWhere('service_jobs.notes', 'LIKE', "%{$s}%")
                        ->orWhere('service_jobs.device_brand', 'LIKE', "%{$s}%")
                        ->orWhere('service_jobs.device_model', 'LIKE', "%{$s}%")
                        ->orWhere('service_jobs.device_serial', 'LIKE', "%{$s}%")
                        ->orWhere('service_jobs.device_imei', 'LIKE', "%{$s}%")
                        ->orWhere('service_jobs.Ref', 'LIKE', "%{$s}%")
                        ->orWhere('clients.name', 'LIKE', "%{$s}%");
                });
            })
            ->when($request->filled('client_id'), function ($q) use ($request) {
                $q->where('service_jobs.client_id', (int) $request->client_id);
            })
            ->when($request->filled('technician_id'), function ($q) use ($request) {
                $q->where('service_jobs.technician_id', (int) $request->technician_id);
            })
            ->when($request->filled('status'), function ($q) use ($request) {
                $q->where('service_jobs.status', $request->status);
            })
            ->when($request->filled('payment_status'), function ($q) use ($request) {
                $q->where('service_jobs.payment_status', $request->payment_status);
            })
            ->when($request->filled('job_type'), function ($q) use ($request) {
                $q->where('service_jobs.job_type', $request->job_type);
            })
            // Service menu: Service Jobs vs Device Repairs.
            ->when(in_array($request->kind, ['service', 'device'], true), function ($q) use ($request) {
                $q->where('service_jobs.job_kind', $request->kind);
            })
            ->when($request->filled('from'), function ($q) use ($request) {
                $q->whereDate('service_jobs.scheduled_date', '>=', $request->from);
            })
            ->when($request->filled('to'), function ($q) use ($request) {
                $q->whereDate('service_jobs.scheduled_date', '<=', $request->to);
            })
            // Day view: every job on that day -- all-day tasks and timed jobs,
            // and multi-day jobs whose range covers it.
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->day), function ($q) use ($request) {
                $q->onDay($request->day);
            });
        // Day view: all-day tasks first, then by time.
        // (Unless the user sorted by another column than the default Ref.)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->day) && in_array((string) $request->SortField, ['', 'Ref'], true)) {
            $order = 'service_jobs.scheduled_date';
            $dir = 'asc';
            $query->orderByDesc('service_jobs.all_day');
        }

        $totalRows = $query->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }

        $jobs = $query->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        $data = [];
        foreach ($jobs as $job) {
            $item['id'] = $job->id;
            $item['Ref'] = $job->Ref;
            $item['client_id'] = $job->client_id;
            $item['client_name'] = $job->client_name ?? null;
            $item['technician_id'] = $job->technician_id;
            $item['assign_all'] = (bool) ($job->assign_all ?? false);
            $item['technician_name'] = ! empty($job->assign_all) ? 'All operators' : ($job->technician_name ?? null);
            $item['service_item'] = $job->service_item;
            $item['device_brand'] = $job->device_brand;
            $item['device_model'] = $job->device_model;
            $item['job_type'] = $job->job_type;
            $item['status'] = $job->status;
            $item['payment_status'] = $job->payment_status;
            $item['total_amount'] = (float) $job->total_amount;
            $item['paid_amount'] = (float) $job->paid_amount;
            $item['balance_due'] = (float) $job->total_amount - (float) $job->paid_amount;
            $item['scheduled_date'] = $job->scheduled_date;
            $item['scheduled_end_date'] = $job->scheduled_end_date;
            $item['all_day'] = (bool) $job->all_day;
            $item['job_kind'] = $job->job_kind ?: 'service';
            $item['started_at'] = $job->started_at;
            $item['completed_at'] = $job->completed_at;
            $item['delivered_at'] = $job->delivered_at;
            $item['warranty_expires_at'] = $job->warranty_expires_at;
            $data[] = $item;
        }

        // Jobs billed on an invoice (service_job_sales): the invoice carries the
        // money, so the list shows it instead of the job's own total / payment.
        $ids = array_column($data, 'id');
        if ($ids && \Illuminate\Support\Facades\Schema::hasTable('service_job_sales')) {
            $inv = DB::table('service_job_sales as l')->join('sales as s', 's.id', '=', 'l.sale_id')->whereNull('s.deleted_at')
                ->whereIn('l.service_job_id', $ids)->get(['l.service_job_id', 's.id', 's.Ref', 's.GrandTotal', 's.paid_amount', 's.payment_statut'])
                ->groupBy('service_job_id');
            foreach ($data as &$row) {
                $list = $inv->get($row['id']);
                $row['invoices'] = $list ? $list->map(fn ($s) => ['id' => $s->id, 'Ref' => $s->Ref, 'GrandTotal' => (float) $s->GrandTotal, 'paid_amount' => (float) $s->paid_amount, 'payment_statut' => $s->payment_statut])->values()->all() : [];
            }
            unset($row);
        }

        return response()->json([
            'jobs' => $data,
            'totalRows' => $totalRows,
        ]);
    }

    // -------------- Meta for create form ---------------\\
    public function create(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', ServiceJob::class);

        $clients = $this->rc(Client::query(), 'clients.company_id')->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'phone']);

        $technicians = $this->rc(ServiceTechnician::query(), 'service_technicians.company_id')->whereNull('deleted_at')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'phone', 'email']);

        // The company's locations (the user's assigned ones when restricted).
        $warehouses = Warehouse::where('deleted_at', '=', null)
            ->when(! $this->reportAllWarehouses(), fn ($q) => $q->whereIn('id', $this->reportWarehouseIdList()))
            ->orderBy('name')
            ->get(['id', 'name']);

        $payment_methods = PaymentMethod::active()->where('deleted_at', '=', null)
            ->get(['id', 'name']);

        // Equipment that can be used on a job: the company's assets (their locations), with hourly cost.
        $assets = \Illuminate\Support\Facades\Schema::hasColumn('assets', 'hourly_cost')
            ? DB::table('assets')->whereNull('deleted_at')->whereIn('warehouse_id', $warehouses->pluck('id'))
                ->whereNotIn('status', ['disposed', 'lost'])->orderBy('name')->get(['id', 'name', 'tag', 'hourly_cost'])
            : [];
        // Technician -> operator rate (hourly employees), to show labor cost live.
        $rates = [];
        foreach ($technicians as $tech) {
            $rates[$tech->id] = \App\Support\ServiceJobCost::operatorRate((int) $tech->id);
        }
        // "All operators" (crew job): the crew's summed rate.
        $rates['all'] = \App\Support\ServiceJobCost::crewRate($this->serviceCompanyId());

        return response()->json([
            'clients' => $clients,
            'technicians' => $technicians,
            'technician_rates' => $rates,
            'assets' => $assets,
            'warehouses' => $warehouses,
            'payment_methods' => $payment_methods,
            'statuses' => [
                'pending', 'intake', 'diagnostic', 'quoted', 'approved',
                'in_progress', 'ready', 'delivered', 'declined', 'cancelled', 'completed',
            ],
            'payment_statuses' => ['unpaid', 'partial', 'paid'],
        ]);
    }

    // ------ Service Jobs list -> "Assign to": set / change / clear the operator ------\\
    public function assign(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', ServiceJob::class);
        $job = $this->scopedJobs()->whereNull('service_jobs.deleted_at')->findOrFail($id);
        // 'all' = "All operators": a crew job any operator can take.
        $all = $request->input('technician_id') === 'all';
        $techId = ! $all && $request->input('technician_id') ? (int) $request->input('technician_id') : null;
        if ($techId) {
            // Only an active operator of the job's company.
            $companyId = \App\Support\ServiceJobLinks::jobCompany((int) $job->id);
            abort_unless(ServiceTechnician::whereKey($techId)->where('company_id', $companyId)->whereNull('deleted_at')->where('is_active', true)->exists(), 422, 'Operator not found in this company.');
        }
        DB::table('service_jobs')->where('id', $job->id)->update(['technician_id' => $techId, 'assign_all' => $all, 'updated_at' => now()]);
        // The labor cost follows the operator's (or the crew's) rate.
        \App\Support\ServiceJobCost::recalc((int) $job->id);

        return response()->json(['success' => true, 'assign_all' => $all, 'technician_name' => $all ? 'All operators' : ($techId ? ServiceTechnician::whereKey($techId)->value('name') : null)]);
    }

    // ------ A customer's invoices, to link to a job (Defaults -> Sales -> "Service jobs on sales") ------\\
    public function clientInvoices(Request $request, $clientId)
    {
        $this->authorizeForUser($request->user('api'), 'view', ServiceJob::class);

        $client = $this->rc(Client::query(), 'clients.company_id')->whereNull('deleted_at')->findOrFail($clientId);
        $cid = (int) $client->company_id;
        $enabled = \App\Support\ServiceJobLinks::enabled($cid);

        return response()->json([
            'enabled' => $enabled,
            'invoices' => $enabled ? \App\Support\ServiceJobLinks::clientInvoices($cid, (int) $client->id) : [],
        ]);
    }

    /**
     * Job costing (Task tab): work_hours, equipment used (job_assets
     * [{asset_id, hours}]) -> labor_cost / asset_cost. Only what was sent.
     */
    protected function syncCosting(Request $request, ServiceJob $job): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('service_jobs', 'work_hours')) {
            return;
        }
        // Task tab -> "All operators" (crew job): no single operator.
        if ($request->has('assign_all') && \Illuminate\Support\Facades\Schema::hasColumn('service_jobs', 'assign_all')) {
            $all = filter_var($request->input('assign_all'), FILTER_VALIDATE_BOOLEAN);
            DB::table('service_jobs')->where('id', $job->id)->update($all ? ['assign_all' => true, 'technician_id' => null] : ['assign_all' => false]);
        }
        if ($request->has('work_hours')) {
            $h = $request->input('work_hours');
            DB::table('service_jobs')->where('id', $job->id)->update(['work_hours' => $h === null || $h === '' ? null : max(0, round((float) $h, 2))]);
        }
        if ($request->has('job_assets')) {
            \App\Support\ServiceJobCost::syncAssets((int) $job->id, (array) $request->input('job_assets', []), \App\Support\ServiceJobLinks::jobCompany((int) $job->id));
        }
        \App\Support\ServiceJobCost::recalc((int) $job->id);
    }

    /** Links the job to the invoices sent in sale_ids (only when the company has it on). */
    protected function syncInvoiceLinks(Request $request, ServiceJob $job): void
    {
        if (! $request->has('sale_ids')) {
            return;
        }
        $cid = \App\Support\ServiceJobLinks::jobCompany((int) $job->id);
        if (\App\Support\ServiceJobLinks::enabled($cid)) {
            \App\Support\ServiceJobLinks::syncForJob((int) $job->id, (array) $request->input('sale_ids', []));
        }
    }

    // -------------- Store New Service Job ---------------\\
    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', ServiceJob::class);

        $validated = $this->validatePayload($request);

        $validated['Ref'] = $this->getNumberOrder();

        $job = DB::transaction(function () use ($validated) {
            $job = ServiceJob::create($this->jobAttributes($validated));
            $this->syncChecklistItems($job, $validated['checklist'] ?? []);
            $this->syncItems($job, $validated['items'] ?? []);
            $this->syncJobWarehouse($job);

            // A job created straight into "delivered" still has to move its stock
            // and land in the period's Profit & Loss.
            if ($job->status === 'delivered') {
                $job->update([
                    'delivered_at' => now(),
                    'completed_at' => $job->completed_at ?: now(),
                ]);
                $this->deductStockForJob($job);
            }

            $this->recalcTotals($job);

            return $job;
        });

        $this->syncInvoiceLinks($request, $job);
        $this->syncCosting($request, $job);

        // A customer's request / work order from Email Office (?email=): link that email to the job.
        if ($request->filled('office_email_id')) {
            // service_jobs has no company_id: the job's location (warehouse) says which company.
            $companyId = (int) DB::table('warehouses')->where('id', $job->warehouse_id)->value('company_id');
            \App\Services\EmailOffice\EmailOffice::linkTo((int) $request->input('office_email_id'), $companyId, 'service_job', (int) $job->id, 'client', (int) $job->client_id);
        }

        return response()->json(['success' => true, 'id' => $job->id], 201);
    }

    // ------------ function show (job + checklist + items + payments + photos) -----------\\
    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', ServiceJob::class);

        $job = $this->scopedJobs()->whereNull('service_jobs.deleted_at')->with(['client', 'technician'])->findOrFail($id);

        $checklist = $job->checklistItems()
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->map(function (ServiceJobChecklistItem $item) {
                return [
                    'id' => $item->id,
                    'category_id' => $item->category_id,
                    'item_id' => $item->item_id,
                    'category_name' => $item->category_name,
                    'item_name' => $item->item_name,
                    'is_completed' => (bool) $item->is_completed,
                    'completed_at' => $item->completed_at,
                ];
            })
            ->all();

        $items = $job->items()
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->map(function (ServiceJobItem $row) {
                return [
                    'id' => $row->id,
                    'type' => $row->type,
                    'product_id' => $row->product_id,
                    'product_variant_id' => $row->product_variant_id,
                    'warehouse_id' => $row->warehouse_id,
                    'description' => $row->description,
                    'quantity' => (float) $row->quantity,
                    'unit_price' => (float) $row->unit_price,
                    'discount' => (float) $row->discount,
                    'discount_method' => $row->discount_method,
                    'tax_rate' => (float) $row->tax_rate,
                    'tax_method' => $row->tax_method,
                    'total' => (float) $row->total,
                    'cost' => (float) $row->cost,
                    'stock_deducted' => (bool) $row->stock_deducted,
                    'notes' => $row->notes,
                ];
            })
            ->all();

        $payments = $job->payments()
            ->whereNull('deleted_at')
            ->with('payment_method:id,name')
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(function (ServiceJobPayment $row) {
                return [
                    'id' => $row->id,
                    'Ref' => $row->Ref,
                    'date' => $row->date ? $row->date->format('Y-m-d') : null,
                    'montant' => (float) $row->montant,
                    'change' => (float) $row->change,
                    'payment_method_id' => $row->payment_method_id,
                    'payment_method' => $row->payment_method ? $row->payment_method->name : null,
                    'account_id' => $row->account_id,
                    'payment_kind' => $row->payment_kind,
                    'notes' => $row->notes,
                ];
            })
            ->all();

        $photos = $job->photos()
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->map(function (ServiceJobPhoto $row) {
                return [
                    'id' => $row->id,
                    'stage' => $row->stage,
                    'path' => $row->path,
                    'url' => asset($row->path),
                    'original_name' => $row->original_name,
                    'mime_type' => $row->mime_type,
                    'size' => $row->size,
                    'caption' => $row->caption,
                    'created_at' => $row->created_at,
                ];
            })
            ->all();

        return response()->json([
            'job' => [
                'id' => $job->id,
                'Ref' => $job->Ref,
                'client_id' => $job->client_id,
                'client_name' => $job->client ? $job->client->name : null,
                'technician_id' => $job->technician_id,
                'assign_all' => (bool) ($job->assign_all ?? false),
                'technician_name' => ! empty($job->assign_all) ? 'All operators' : ($job->technician ? $job->technician->name : null),
                'warehouse_id' => $job->warehouse_id,
                'service_item' => $job->service_item,
                'job_type' => $job->job_type,
                'status' => $job->status,
                'scheduled_date' => $job->scheduled_date,
                'scheduled_end_date' => $job->scheduled_end_date,
                'all_day' => (bool) $job->all_day,
                'job_kind' => $job->job_kind ?: 'service',
                'started_at' => $job->started_at,
                'completed_at' => $job->completed_at,
                'notes' => $job->notes,

                'device_brand' => $job->device_brand,
                'device_model' => $job->device_model,
                'device_serial' => $job->device_serial,
                'device_imei' => $job->device_imei,
                'device_color' => $job->device_color,
                'device_password' => $job->device_password,
                'accessories' => $job->accessories ?? [],

                'condition_on_arrival' => $job->condition_on_arrival,
                'reported_issue' => $job->reported_issue,
                'diagnosis' => $job->diagnosis,
                'diagnostic_fee' => (float) $job->diagnostic_fee,

                'quote_amount' => (float) $job->quote_amount,
                'quote_valid_until' => $job->quote_valid_until ? Carbon::parse($job->quote_valid_until)->format('Y-m-d') : null,
                'quote_approved_at' => $job->quote_approved_at,
                'quote_approved_by' => $job->quote_approved_by,
                'quotation_id' => $job->quotation_id,
                'quotation_ref' => $job->quotation_id ? optional(Quotation::find($job->quotation_id))->Ref : null,

                'total_amount' => (float) $job->total_amount,
                'paid_amount' => (float) $job->paid_amount,
                'balance_due' => (float) $job->total_amount - (float) $job->paid_amount,
                'payment_status' => $job->payment_status,

                'warranty_days' => (int) $job->warranty_days,
                'warranty_expires_at' => $job->warranty_expires_at ? Carbon::parse($job->warranty_expires_at)->format('Y-m-d') : null,
                'parent_job_id' => $job->parent_job_id,

                'delivered_at' => $job->delivered_at,
                'pickup_signature' => $job->pickup_signature,
            ],
            'checklist' => $checklist,
            'items' => $items,
            'payments' => $payments,
            'photos' => $photos,
            // Invoices that bill this job (service_job_sales).
            'invoices' => \App\Support\ServiceJobLinks::invoicesOfJob((int) $job->id),
            // Job costing: hours worked, operator rate, equipment used, costs.
            'costing' => [
                'work_hours' => $job->work_hours !== null ? (float) $job->work_hours : null,
                'operator_rate' => \App\Support\ServiceJobCost::jobRate((int) $job->id),
                'assign_all' => (bool) ($job->assign_all ?? false),
                'labor_cost' => (float) ($job->labor_cost ?? 0),
                'asset_cost' => (float) ($job->asset_cost ?? 0),
                'total_cost' => round((float) ($job->labor_cost ?? 0) + (float) ($job->asset_cost ?? 0), 2),
                'assets' => \App\Support\ServiceJobCost::assetsOf((int) $job->id),
            ],
        ]);
    }

    // -------------- Update Service Job ---------------\\
    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', ServiceJob::class);

        $job = $this->scopedJobs()->whereNull('service_jobs.deleted_at')->findOrFail($id);

        $validated = $this->validatePayload($request);

        $warnings = DB::transaction(function () use ($job, $validated) {
            $job->update($this->jobAttributes($validated, $job));

            if (array_key_exists('checklist', $validated)) {
                $this->syncChecklistItems($job, $validated['checklist'] ?? []);
            }

            if (array_key_exists('items', $validated)) {
                $this->syncItems($job, $validated['items'] ?? []);
                $this->syncJobWarehouse($job);
            }

            // The status dropdown can move a job to delivered without going through
            // markDelivered(), so stamp the delivery here too — Profit & Loss buckets
            // service revenue by delivered_at and would otherwise miss the job entirely.
            if ($job->status === 'delivered' && ! $job->delivered_at) {
                $job->update([
                    'delivered_at' => now(),
                    'completed_at' => $job->completed_at ?: now(),
                ]);
            }

            // Lines added or re-shaped above sit at stock_deducted = false. On a delivered
            // job they must move now, since markDelivered() will not run for it again.
            $warnings = $job->status === 'delivered'
                ? $this->deductStockForJob($job)
                : [];

            $this->recalcTotals($job);

            return $warnings;
        });

        $this->syncInvoiceLinks($request, $job);
        $this->syncCosting($request, $job);

        return response()->json([
            'success' => true,
            'warnings' => $warnings,
        ]);
    }

    // -------------- Delete Service Job ---------------\\
    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', ServiceJob::class);

        $job = $this->scopedJobs()->findOrFail($id);

        DB::transaction(function () use ($job) {
            // Deleting a delivered job has to put its parts back on the shelf,
            // otherwise the deduction is stranded with no document behind it.
            $this->restockForJob($job);
            $job->update(['deleted_at' => now()]);
        });

        return response()->json(['success' => true]);
    }

    // -------------- Approve Quote ---------------\\
    public function approveQuote(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', ServiceJob::class);

        $job = $this->scopedJobs()->whereNull('service_jobs.deleted_at')->findOrFail($id);

        $validated = $request->validate([
            'approved_by' => 'nullable|string|max:191',
        ]);

        $u = $request->user('api');
        $approverFallback = $u
            ? ($u->firstname ?: ($u->username ?: ($u->email ?: 'staff')))
            : 'customer';

        $job->update([
            'status' => 'approved',
            'quote_approved_at' => now(),
            'quote_approved_by' => $validated['approved_by'] ?? $approverFallback,
        ]);

        return response()->json(['success' => true]);
    }

    // -------------- Decline Quote ---------------\\
    public function declineQuote(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', ServiceJob::class);

        $job = $this->scopedJobs()->whereNull('service_jobs.deleted_at')->findOrFail($id);

        $job->update(['status' => 'declined']);

        return response()->json(['success' => true]);
    }

    // -------------- Mark Delivered (decrements stock + sets warranty) ---------------\\
    public function markDelivered(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', ServiceJob::class);

        $job = $this->scopedJobs()->whereNull('service_jobs.deleted_at')->findOrFail($id);

        $validated = $request->validate([
            'pickup_signature' => 'nullable|string|max:191',
        ]);

        // Use the same 0.0001 threshold as recalcTotals() to keep payment_status and
        // delivery-eligibility checks consistent under float-precision drift.
        if (((float) $job->total_amount - (float) $job->paid_amount) > 0.0001) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot deliver: outstanding balance must be paid first.',
            ], 422);
        }

        $warnings = DB::transaction(function () use ($job, $validated) {
            $warnings = $this->deductStockForJob($job);

            $warrantyDays = (int) ($job->warranty_days ?? 30);
            $job->update([
                'status' => 'delivered',
                'delivered_at' => now(),
                'completed_at' => $job->completed_at ?: now(),
                'pickup_signature' => $validated['pickup_signature'] ?? null,
                'warranty_expires_at' => $warrantyDays > 0 ? now()->addDays($warrantyDays)->toDateString() : null,
            ]);

            return $warnings;
        });

        return response()->json([
            'success' => true,
            'warnings' => $warnings,
        ]);
    }

    // -------------- Helper: validation rules (shared by store + update) ---------------\\
    protected function validatePayload(Request $request): array
    {
        $validated = $this->validateRules($request);
        $this->assertJobRefs($validated);

        return $validated;
    }

    /**
     * Customer, technician, location, parent job and the parts' products /
     * locations must all be the acting company's (a SuperAdmin with no
     * company picked is not restricted).
     */
    protected function assertJobRefs(array $v): void
    {
        $cid = $this->reportCompanyId();
        if (! $cid) {
            return;
        }
        $warehouses = $this->reportWarehouseIdList();
        abort_unless(Client::whereKey($v['client_id'] ?? 0)->where('company_id', $cid)->exists(), 422, 'That customer does not belong to your company.');
        abort_if(! empty($v['technician_id']) && ! ServiceTechnician::whereKey($v['technician_id'])->where('company_id', $cid)->exists(), 422, 'That technician does not belong to your company.');
        abort_if(! empty($v['warehouse_id']) && ! in_array((int) $v['warehouse_id'], $warehouses, true), 422, 'That location does not belong to your company.');
        abort_if(! empty($v['parent_job_id']) && ! $this->scopedJobs()->whereKey($v['parent_job_id'])->exists(), 422, 'That parent job does not belong to your company.');
        foreach ($v['items'] ?? [] as $item) {
            abort_if(! empty($item['warehouse_id']) && ! in_array((int) $item['warehouse_id'], $warehouses, true), 422, 'A part line uses a location of another company.');
            abort_if(! empty($item['product_id']) && ! DB::table('products')->where('id', $item['product_id'])->where('company_id', $cid)->exists(), 422, 'A part line uses a product of another company.');
        }
    }

    protected function validateRules(Request $request): array
    {
        return $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'technician_id' => 'nullable|integer|exists:service_technicians,id',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'service_item' => 'required|string|max:191',
            'job_type' => 'nullable|string|max:191',
            'status' => 'nullable|string|max:50',
            'scheduled_date' => 'nullable|date',
            'scheduled_end_date' => 'nullable|date|after_or_equal:scheduled_date',
            // No time chosen: an all-day task on its day(s).
            'all_day' => 'nullable|boolean',
            'job_kind' => 'nullable|in:service,device',
            'started_at' => 'nullable|date',
            'completed_at' => 'nullable|date',
            'notes' => 'nullable|string',

            'device_brand' => 'nullable|string|max:191',
            'device_model' => 'nullable|string|max:191',
            'device_serial' => 'nullable|string|max:191',
            'device_imei' => 'nullable|string|max:191',
            'device_color' => 'nullable|string|max:100',
            'device_password' => 'nullable|string|max:191',
            'accessories' => 'nullable|array',
            'accessories.*' => 'string|max:100',

            'condition_on_arrival' => 'nullable|string',
            'reported_issue' => 'nullable|string',
            'diagnosis' => 'nullable|string',
            'diagnostic_fee' => 'nullable|numeric',

            'quote_amount' => 'nullable|numeric',
            'quote_valid_until' => 'nullable|date',

            'warranty_days' => 'nullable|integer|min:0|max:3650',
            'parent_job_id' => 'nullable|integer|exists:service_jobs,id',

            'checklist' => 'nullable|array',
            'checklist.*.category_id' => 'nullable|integer',
            'checklist.*.category_name' => 'nullable|string|max:191',
            'checklist.*.item_id' => 'nullable|integer',
            'checklist.*.item_name' => 'required_with:checklist|string|max:191',
            'checklist.*.is_completed' => 'nullable|boolean',

            'items' => 'nullable|array',
            'items.*.id' => 'nullable|integer',
            'items.*.type' => 'nullable|string|in:part,labor,other',
            'items.*.product_id' => 'nullable|integer',
            'items.*.product_variant_id' => 'nullable|integer',
            'items.*.warehouse_id' => 'nullable|integer',
            'items.*.description' => 'required_with:items|string|max:191',
            'items.*.quantity' => 'nullable|numeric|min:0',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'items.*.discount_method' => 'nullable|string|in:1,2',
            'items.*.tax_rate' => 'nullable|numeric|min:0',
            'items.*.tax_method' => 'nullable|string|in:1,2',
            'items.*.notes' => 'nullable|string',
        ]);
    }

    // -------------- Helper: build attributes for job create/update ---------------\\
    protected function jobAttributes(array $validated, ?ServiceJob $existing = null): array
    {
        $attrs = [
            'client_id' => $validated['client_id'],
            'technician_id' => $validated['technician_id'] ?? null,
            'warehouse_id' => $validated['warehouse_id'] ?? ($existing->warehouse_id ?? null),
            'service_item' => $validated['service_item'],
            'job_type' => $validated['job_type'] ?? null,
            'status' => $validated['status'] ?? ($existing->status ?? 'pending'),
            // One day or a range, at a time or all day (ServiceJob::normalizeSchedule).
            ...ServiceJob::normalizeSchedule($validated),
            'job_kind' => $validated['job_kind'] ?? ($existing->job_kind ?? 'service'),
            'started_at' => $validated['started_at'] ?? null,
            'completed_at' => $validated['completed_at'] ?? null,
            'notes' => $validated['notes'] ?? null,

            'device_brand' => $validated['device_brand'] ?? null,
            'device_model' => $validated['device_model'] ?? null,
            'device_serial' => $validated['device_serial'] ?? null,
            'device_imei' => $validated['device_imei'] ?? null,
            'device_color' => $validated['device_color'] ?? null,
            'device_password' => $validated['device_password'] ?? null,
            'accessories' => $validated['accessories'] ?? null,

            'condition_on_arrival' => $validated['condition_on_arrival'] ?? null,
            'reported_issue' => $validated['reported_issue'] ?? null,
            'diagnosis' => $validated['diagnosis'] ?? null,
            'diagnostic_fee' => $validated['diagnostic_fee'] ?? 0,

            'quote_amount' => $validated['quote_amount'] ?? 0,
            'quote_valid_until' => $validated['quote_valid_until'] ?? null,

            'warranty_days' => $validated['warranty_days'] ?? ($existing->warranty_days ?? 30),
            'parent_job_id' => $validated['parent_job_id'] ?? null,
        ];

        if (isset($validated['Ref'])) {
            $attrs['Ref'] = $validated['Ref'];
        }

        return $attrs;
    }

    // -------------- Helper: sync checklist items ---------------\\
    protected function syncChecklistItems(ServiceJob $job, array $items): void
    {
        ServiceJobChecklistItem::where('service_job_id', $job->id)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);

        foreach ($items as $item) {
            if (! isset($item['item_name']) || $item['item_name'] === null || $item['item_name'] === '') {
                continue;
            }

            ServiceJobChecklistItem::create([
                'service_job_id' => $job->id,
                'category_id' => $item['category_id'] ?? null,
                'item_id' => $item['item_id'] ?? null,
                'category_name' => $item['category_name'] ?? null,
                'item_name' => $item['item_name'],
                'is_completed' => isset($item['is_completed']) ? (bool) $item['is_completed'] : false,
                'completed_at' => ! empty($item['is_completed']) ? now() : null,
            ]);
        }
    }

    // -------------- Helper: sync line items (parts + labor) ---------------\\
    protected function syncItems(ServiceJob $job, array $items): void
    {
        // Lines are matched by id and updated in place. Recreating them wholesale
        // would duplicate any line that had already deducted stock (those cannot be
        // soft-deleted blindly), which double-counted the job total on every edit.
        $existing = ServiceJobItem::where('service_job_id', $job->id)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('id');

        $keptIds = [];

        foreach ($items as $item) {
            if (! isset($item['description']) || $item['description'] === '') {
                continue;
            }

            $attrs = $this->itemAttributes($job, $item);

            $id = isset($item['id']) ? (int) $item['id'] : 0;
            $row = $id ? $existing->get($id) : null;

            if ($row) {
                if ($row->stock_deducted && $this->stockShapeChanged($row, $attrs)) {
                    // Product / variant / warehouse / quantity moved on a line that already
                    // took stock: hand the old quantity back, then let it be re-deducted
                    // with the new shape (immediately if the job is already delivered).
                    $this->restockItem($row);
                    $attrs['stock_deducted'] = false;
                    $attrs['cost'] = 0;
                } else {
                    $attrs['stock_deducted'] = $row->stock_deducted;
                    $attrs['cost'] = $row->cost;
                }

                $row->update($attrs);
                $keptIds[] = $row->id;

                continue;
            }

            $attrs['stock_deducted'] = false;
            $attrs['cost'] = 0;
            $keptIds[] = ServiceJobItem::create($attrs)->id;
        }

        // Lines dropped from the payload give their stock back before being removed.
        foreach ($existing->except($keptIds) as $row) {
            $this->restockItem($row);
            $row->update(['deleted_at' => now()]);
        }
    }

    // -------------- Helper: build one line item's attributes ---------------\\
    protected function itemAttributes(ServiceJob $job, array $item): array
    {
        $qty = (float) ($item['quantity'] ?? 1);
        $price = (float) ($item['unit_price'] ?? 0);
        $discount = (float) ($item['discount'] ?? 0);
        $discountMethod = $item['discount_method'] ?? '1';
        $taxRate = (float) ($item['tax_rate'] ?? 0);
        $taxMethod = $item['tax_method'] ?? '1';

        $subtotal = $qty * $price;
        $discountValue = $discountMethod === '2' ? ($subtotal * $discount / 100) : $discount;
        $afterDiscount = max(0, $subtotal - $discountValue);
        $taxValue = $taxMethod === '2' ? 0 : ($afterDiscount * $taxRate / 100);
        $total = $afterDiscount + $taxValue;

        return [
            'service_job_id' => $job->id,
            'type' => $item['type'] ?? 'part',
            'product_id' => $item['product_id'] ?? null,
            'product_variant_id' => $item['product_variant_id'] ?? null,
            'warehouse_id' => $item['warehouse_id'] ?? null,
            'description' => $item['description'],
            'quantity' => $qty,
            'unit_price' => $price,
            'discount' => $discount,
            'discount_method' => $discountMethod,
            'tax_rate' => $taxRate,
            'tax_method' => $taxMethod,
            'total' => $total,
            'notes' => $item['notes'] ?? null,
        ];
    }

    // -------------- Helper: did an edit invalidate an already-deducted line? ---------------\\
    protected function stockShapeChanged(ServiceJobItem $row, array $attrs): bool
    {
        return (string) $row->type !== (string) ($attrs['type'] ?? 'part')
            || (int) $row->product_id !== (int) ($attrs['product_id'] ?? 0)
            || (int) $row->product_variant_id !== (int) ($attrs['product_variant_id'] ?? 0)
            || (int) $row->warehouse_id !== (int) ($attrs['warehouse_id'] ?? 0)
            || abs((float) $row->quantity - (float) ($attrs['quantity'] ?? 0)) > 0.0001;
    }

    // -------------- Helper: keep the job's warehouse aligned with its parts ---------------\\
    /**
     * Profit & Loss buckets a job's revenue and its parts cost by service_jobs.warehouse_id,
     * so the job needs a warehouse even when the client didn't pick one explicitly.
     */
    protected function syncJobWarehouse(ServiceJob $job): void
    {
        if ($job->warehouse_id) {
            return;
        }

        $warehouseId = ServiceJobItem::where('service_job_id', $job->id)
            ->whereNull('deleted_at')
            ->whereNotNull('warehouse_id')
            ->orderBy('id')
            ->value('warehouse_id');

        if ($warehouseId) {
            $job->update(['warehouse_id' => $warehouseId]);
        }
    }

    // -------------- Helper: recompute totals + payment_status ---------------\\
    public function recalcTotals(ServiceJob $job): void
    {
        $itemsTotal = (float) ServiceJobItem::where('service_job_id', $job->id)
            ->whereNull('deleted_at')
            ->sum('total');

        $diagnostic = (float) ($job->diagnostic_fee ?? 0);

        // If quote_amount was set manually and there are no line items, fall back to it.
        if ($itemsTotal <= 0 && (float) $job->quote_amount > 0) {
            $totalAmount = (float) $job->quote_amount + $diagnostic;
        } else {
            $totalAmount = $itemsTotal + $diagnostic;
        }

        $paid = (float) ServiceJobPayment::where('service_job_id', $job->id)
            ->whereNull('deleted_at')
            ->sum('montant');

        $balance = $totalAmount - $paid;

        if ($paid <= 0) {
            $paymentStatus = 'unpaid';
        } elseif ($balance > 0.0001) {
            $paymentStatus = 'partial';
        } else {
            $paymentStatus = 'paid';
        }

        $job->update([
            'total_amount' => $totalAmount,
            'paid_amount' => $paid,
            'payment_status' => $paymentStatus,
        ]);
    }

    // -------------- Helper: deduct stock for parts on this job ---------------\\
    /**
     * Takes stock for every part line that hasn't moved yet and snapshots its unit
     * cost, so the job's profit stays fixed at the cost that applied on the day the
     * part left the shelf.
     *
     * @return array<int, string> human-readable warnings for lines that could not move stock
     */
    protected function deductStockForJob(ServiceJob $job): array
    {
        $warnings = [];

        $parts = ServiceJobItem::where('service_job_id', $job->id)
            ->whereNull('deleted_at')
            ->where('type', 'part')
            ->where('stock_deducted', false)
            ->get();

        // Previously these lines were filtered out in SQL and vanished without a trace.
        // Now they are reported back so the counter staff can fix the job.
        $deductable = $parts->filter(function (ServiceJobItem $row) use (&$warnings) {
            if (! $row->product_id) {
                $warnings[] = "\"{$row->description}\" is not linked to a product, so no stock was deducted.";

                return false;
            }

            if (! $row->warehouse_id) {
                $warnings[] = "\"{$row->description}\" has no warehouse selected, so no stock was deducted.";

                return false;
            }

            return true;
        })->values();

        if ($deductable->isEmpty()) {
            return $warnings;
        }

        $costs = $this->resolveUnitCosts($deductable);

        foreach ($deductable as $row) {
            $this->applyStockDelta(
                (int) $row->warehouse_id,
                (int) $row->product_id,
                $row->product_variant_id,
                -(float) $row->quantity
            );

            $unitCost = (float) ($costs[$row->id] ?? 0);
            if ($unitCost <= 0) {
                $warnings[] = "\"{$row->description}\" has no purchase or product cost on record, so it is reported as pure profit.";
            }

            $row->cost = $unitCost;
            $row->stock_deducted = true;
            $row->save();
        }

        return $warnings;
    }

    // -------------- Helper: give a line's stock back ---------------\\
    protected function restockItem(ServiceJobItem $row): void
    {
        if (! $row->stock_deducted) {
            return;
        }

        $this->applyStockDelta(
            (int) $row->warehouse_id,
            (int) $row->product_id,
            $row->product_variant_id,
            (float) $row->quantity
        );

        $row->stock_deducted = false;
        $row->save();
    }

    // -------------- Helper: give every deducted line's stock back ---------------\\
    protected function restockForJob(ServiceJob $job): void
    {
        $rows = ServiceJobItem::where('service_job_id', $job->id)
            ->whereNull('deleted_at')
            ->where('stock_deducted', true)
            ->get();

        foreach ($rows as $row) {
            $this->restockItem($row);
        }
    }

    // -------------- Helper: move warehouse stock by a signed delta ---------------\\
    protected function applyStockDelta(?int $warehouseId, ?int $productId, ?int $variantId, float $delta): void
    {
        if (! $warehouseId || ! $productId || abs($delta) < 0.0001) {
            return;
        }

        $query = product_warehouse::whereNull('deleted_at')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId);

        if ($variantId !== null) {
            $query->where('product_variant_id', $variantId);
        } else {
            $query->whereNull('product_variant_id');
        }

        $pw = $query->lockForUpdate()->first();

        if (! $pw) {
            // No stock row for this product/warehouse pair yet. Create one rather than
            // dropping the movement: the resulting negative qty is visible and fixable,
            // a swallowed deduction is neither.
            $pw = product_warehouse::create([
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'qte' => 0,
                'manage_stock' => 1,
            ]);
        }

        $pw->qte = (float) $pw->qte + $delta;
        $pw->save();
    }

    // -------------- Helper: unit cost per line at deduction time ---------------\\
    /**
     * Same fallback chain as the Profit & Loss report: moving-average cost derived from
     * purchases/adjustments, then the variant's cost, then the product's cost.
     *
     * @return array<int, float> line id => unit cost
     */
    protected function resolveUnitCosts($rows): array
    {
        $end = now()->toDateString();
        $costs = [];

        $productIds = $rows->pluck('product_id')->filter()->unique()->values()->all();
        $variantIds = $rows->pluck('product_variant_id')->filter()->unique()->values()->all();

        $productCosts = Product::whereIn('id', $productIds)->pluck('cost', 'id');
        $variantCosts = empty($variantIds)
            ? collect()
            : ProductVariant::whereIn('id', $variantIds)->pluck('cost', 'id');

        // Average cost is warehouse-scoped, so resolve one warehouse at a time.
        foreach ($rows->groupBy('warehouse_id') as $warehouseId => $group) {
            $wid = (int) $warehouseId;

            $avg = $this->averageCostBulk(
                $group->pluck('product_id')->filter()->unique()->values()->all(),
                $group->pluck('product_variant_id')->filter()->unique()->values()->all(),
                $end,
                $wid,
                [$wid]
            );

            foreach ($group as $row) {
                $unit = (float) ($avg[$row->product_id.':'.($row->product_variant_id ?? 'null')] ?? 0);

                if ($unit <= 0 && $row->product_variant_id && isset($variantCosts[$row->product_variant_id])) {
                    $unit = (float) $variantCosts[$row->product_variant_id];
                }

                if ($unit <= 0 && isset($productCosts[$row->product_id])) {
                    $unit = (float) $productCosts[$row->product_id];
                }

                $costs[$row->id] = max(0.0, $unit);
            }
        }

        return $costs;
    }

    /**
     * Generate reference number for service jobs.
     */
    public function getNumberOrder()
    {
        $last = DB::table('service_jobs')->latest('id')->first();

        if ($last && $last->Ref) {
            $item = $last->Ref;
            $nwMsg = explode('_', $item);
            $inMsg = isset($nwMsg[1]) ? ($nwMsg[1] + 1) : 1112;
            $code = 'SJ_'.$inMsg;
        } else {
            $code = 'SJ_1111';
        }

        return $code;
    }

    /**
     * Build the array passed to the service job / quote PDF blades.
     */
    protected function buildPdfJobData(ServiceJob $job): array
    {
        $checklist = $job->checklistItems()
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->map(function (ServiceJobChecklistItem $item) {
                return [
                    'category_name' => $item->category_name,
                    'item_name' => $item->item_name,
                    'is_completed' => (bool) $item->is_completed,
                ];
            })
            ->all();

        $items = $job->items()
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->map(function (ServiceJobItem $row) {
                return [
                    'type' => $row->type,
                    'description' => $row->description,
                    'quantity' => (float) $row->quantity,
                    'unit_price' => (float) $row->unit_price,
                    'discount' => (float) $row->discount,
                    'tax_rate' => (float) $row->tax_rate,
                    'total' => (float) $row->total,
                ];
            })
            ->all();

        $payments = $job->payments()
            ->whereNull('deleted_at')
            ->with('payment_method:id,name')
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(function (ServiceJobPayment $row) {
                return [
                    'Ref' => $row->Ref,
                    'date' => $row->date ? $row->date->format('Y-m-d') : null,
                    'montant' => (float) $row->montant,
                    'payment_kind' => $row->payment_kind,
                    'payment_method' => $row->payment_method ? $row->payment_method->name : null,
                ];
            })
            ->all();

        return [
            'id' => $job->id,
            'Ref' => $job->Ref,
            'client_name' => optional($job->client)->name ?? '-',
            'client_email' => optional($job->client)->email ?? '-',
            'client_phone' => optional($job->client)->phone ?? '-',
            'client_adr' => optional($job->client)->adresse ?? '-',
            'technician_name' => optional($job->technician)->name ?? '-',
            'service_item' => $job->service_item,
            'job_type' => $job->job_type,
            'status' => $job->status,
            'scheduled_date' => $job->scheduled_date,
            'scheduled_end_date' => $job->scheduled_end_date,
            'started_at' => $job->started_at,
            'completed_at' => $job->completed_at,
            'delivered_at' => $job->delivered_at,
            'notes' => $job->notes,

            'device_brand' => $job->device_brand,
            'device_model' => $job->device_model,
            'device_color' => $job->device_color,
            'device_serial' => $job->device_serial,
            'device_imei' => $job->device_imei,
            'accessories' => is_array($job->accessories) ? $job->accessories : [],

            'condition_on_arrival' => $job->condition_on_arrival,
            'reported_issue' => $job->reported_issue,
            'diagnosis' => $job->diagnosis,
            'diagnostic_fee' => (float) $job->diagnostic_fee,

            'quote_amount' => (float) $job->quote_amount,
            'quote_valid_until' => $job->quote_valid_until,
            'quote_approved_at' => $job->quote_approved_at,
            'quote_approved_by' => $job->quote_approved_by,

            'total_amount' => (float) $job->total_amount,
            'paid_amount' => (float) $job->paid_amount,
            'payment_status' => $job->payment_status,

            'warranty_days' => (int) $job->warranty_days,
            'warranty_expires_at' => $job->warranty_expires_at,
            'pickup_signature' => $job->pickup_signature,

            'checklist' => $checklist,
            'items' => $items,
            'payments' => $payments,
        ];
    }

    /**
     * Render an Arabic-aware PDF and return a download response.
     */
    protected function renderPdf(string $viewName, array $payload, string $filename)
    {
        $Html = view($viewName, $payload)->render();

        $arabic = new Arabic;
        $p = $arabic->arIdentify($Html);
        for ($i = count($p) - 1; $i >= 0; $i -= 2) {
            $utf8ar = $arabic->utf8Glyphs(substr($Html, $p[$i - 1], $p[$i] - $p[$i - 1]));
            $Html = substr_replace($Html, $utf8ar, $p[$i - 1], $p[$i] - $p[$i - 1]);
        }

        return PDF::loadHTML($Html)->download($filename);
    }

    /**
     * Generate work-order / invoice PDF for a service job.
     */
    public function service_job_pdf(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', ServiceJob::class);
        $helpers = new helpers;
        $job = $this->scopedJobs()->with(['client', 'technician'])
            ->whereNull('deleted_at')
            ->findOrFail($id);

        return $this->renderPdf('pdf.service_job_pdf', [
            'symbol' => $helpers->Get_Currency_Code(),
            'setting' => Setting::current(),
            'job' => $this->buildPdfJobData($job),
        ], 'Service_Job_'.$job->id.'.pdf');
    }

    /**
     * Generate the customer-facing repair quote PDF.
     */
    public function service_quote_pdf(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', ServiceJob::class);
        $helpers = new helpers;
        $job = $this->scopedJobs()->with(['client', 'technician'])
            ->whereNull('deleted_at')
            ->findOrFail($id);

        return $this->renderPdf('pdf.service_quote_pdf', [
            'symbol' => $helpers->Get_Currency_Code(),
            'setting' => Setting::current(),
            'job' => $this->buildPdfJobData($job),
        ], 'Service_Quote_'.$job->id.'.pdf');
    }

    /**
     * Convert a service job's quote into a record in the main quotations list.
     * Idempotent: if already linked, returns the existing quotation_id.
     */
    public function createQuotation(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', ServiceJob::class);
        $this->authorizeForUser($request->user('api'), 'create', Quotation::class);

        $job = $this->scopedJobs()->with('items')->whereNull('deleted_at')->findOrFail($id);

        if ($job->quotation_id) {
            $existing = Quotation::find($job->quotation_id);
            if ($existing) {
                return response()->json([
                    'success' => true,
                    'duplicate' => true,
                    'quotation_id' => $existing->id,
                    'Ref' => $existing->Ref,
                ]);
            }
        }

        if (! $job->client_id) {
            return response()->json(['message' => 'This service job has no customer; assign one before creating a quotation.'], 422);
        }

        $user = Auth::user();

        // Pick a warehouse the current user can access. Prefer one referenced by the job's items.
        $warehouseId = optional($job->items->firstWhere('warehouse_id', '!=', null))->warehouse_id;
        if (! $warehouseId) {
            // The job's company DEFAULT warehouse (settings.warehouse_id) when the
            // user may use it, else the first warehouse they may use.
            $companyId = (int) (DB::table('clients')->where('id', $job->client_id)->value('company_id') ?: $user->company_id);
            $default = (int) Setting::forCompany($companyId)->warehouse_id;
            $allowed = $this->reportAllWarehouses() ? null : $this->reportWarehouseIdList();
            $warehouseId = ($default && ($allowed === null || in_array($default, $allowed, true)))
                ? $default
                : ($allowed[0] ?? Warehouse::whereNull('deleted_at')->where('company_id', $companyId)->orderBy('id')->value('id'));
        }
        if (! $warehouseId) {
            return response()->json(['message' => 'No location is accessible to your account; cannot create a quotation.'], 422);
        }

        $itemsTotal = (float) $job->items->sum('total');
        $diagnostic = (float) ($job->diagnostic_fee ?? 0);
        $grandTotal = $itemsTotal > 0 ? $itemsTotal + $diagnostic : (float) ($job->total_amount ?: $job->quote_amount ?: 0);

        $quotation = DB::transaction(function () use ($job, $user, $warehouseId, $grandTotal) {
            $year = date('Y');
            $prefix = "QT_SVC_{$year}_";
            $lastRef = Quotation::where('Ref', 'LIKE', $prefix . '%')
                ->orderByDesc('id')->value('Ref');
            $next = $lastRef ? ((int) substr($lastRef, strlen($prefix))) + 1 : 1;
            $ref = sprintf('QT_SVC_%s_%05d', $year, $next);

            $notesParts = [];
            if ($job->Ref) {
                $notesParts[] = 'From service job: ' . $job->Ref;
            }
            if ($job->reported_issue) {
                $notesParts[] = 'Reported issue: ' . $job->reported_issue;
            }
            if ($job->diagnosis) {
                $notesParts[] = 'Diagnosis: ' . $job->diagnosis;
            }
            // Labor / non-product lines have no product_id and can't go into quotation_details.
            $laborLines = $job->items->filter(fn ($i) => empty($i->product_id))
                ->map(fn ($i) => '- ' . ($i->description ?: 'Item') . ' (' . $i->quantity . ' x ' . number_format($i->unit_price, 2) . ' = ' . number_format($i->total, 2) . ')')
                ->values();
            if ($laborLines->count()) {
                $notesParts[] = "Labor / non-product items:\n" . $laborLines->implode("\n");
            }
            if ($diagnostic > 0) {
                $notesParts[] = 'Diagnostic fee: ' . number_format($diagnostic, 2);
            }

            $quotation = Quotation::create([
                'date' => now()->toDateString(),
                'time' => now()->toTimeString(),
                'Ref' => $ref,
                'client_id' => $job->client_id,
                'warehouse_id' => $warehouseId,
                'user_id' => $user->id,
                'GrandTotal' => $grandTotal,
                'discount' => 0,
                'shipping' => 0,
                'TaxNet' => 0,
                'tax_rate' => 0,
                'statut' => 'pending',
                'notes' => implode("\n\n", $notesParts),
            ]);

            foreach ($job->items as $item) {
                if (empty($item->product_id)) {
                    continue; // captured in notes
                }
                QuotationDetail::create([
                    'quotation_id' => $quotation->id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'quantity' => $item->quantity,
                    'price' => $item->unit_price,
                    'total' => $item->total,
                    'discount' => $item->discount ?? 0,
                    'discount_method' => $item->discount_method ?? '2',
                    'TaxNet' => $item->tax_rate ?? 0,
                    'tax_method' => $item->tax_method ?? '1',
                ]);
            }

            $job->update(['quotation_id' => $quotation->id]);

            return $quotation;
        });

        return response()->json([
            'success' => true,
            'quotation_id' => $quotation->id,
            'Ref' => $quotation->Ref,
        ], 201);
    }
}
