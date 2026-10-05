<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Client;
use App\Models\Product;
use App\Models\SalesContract;
use App\Models\SalesContractInvoice;
use App\Models\SalesContractItem;
use App\Models\ServiceTechnician;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Services\SalesContractBilling;
use App\Services\SalesContractJobs;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sales > Sales Contracts (customer contracts with recurring invoices).
 * Employee contracts are a separate module (ContractController, HRM).
 *
 * Multicompany: everything is scoped to the acting company (an Admin's own;
 * a SuperAdmin's selected one). Permissions (Roles > Sales Contracts):
 * sales_contracts_view / _add / _edit / _delete / _invoice.
 */
class SalesContractController extends Controller
{
    use ScopesToCompany;

    private function need(Request $request, string $permission): void
    {
        $user = $request->user('api');
        abort_unless($user, 401);
        if ($user->type === 'SuperAdmin') {
            return;
        }
        abort_unless((bool) $user->roles()->first()?->permissions->contains('name', $permission), 403,
            'You do not have permission for this (Sales Contracts).');
    }

    private function companyId(Request $request): int
    {
        return $this->resolveActingCompanyId($request);
    }

    private function find(Request $request, $id): SalesContract
    {
        return SalesContract::whereNull('deleted_at')->where('company_id', $this->companyId($request))->findOrFail($id);
    }

    public function index(Request $request)
    {
        $this->need($request, 'sales_contracts_view');
        $cid = $this->companyId($request);
        $perPage = (int) ($request->limit ?: 10);
        $page = max(1, (int) $request->get('page', 1));
        $sortable = ['id', 'contract_number', 'subject', 'value', 'start_date', 'end_date', 'status', 'next_invoice_date'];
        $order = in_array($request->SortField, $sortable, true) ? $request->SortField : 'id';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $q = SalesContract::with('client:id,name')->whereNull('deleted_at')->where('company_id', $cid)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', (int) $request->client_id))
            ->when($request->filled('billing'), fn ($q) => $q->where('billing_enabled', $request->billing === 'recurring'))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(fn ($q) => $q->where('contract_number', 'like', "%$s%")->orWhere('subject', 'like', "%$s%")
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%$s%")));
            });
        $total = (clone $q)->count();
        if ($perPage === -1) {
            $perPage = max(1, $total);
        }
        $rows = $q->orderBy($order, $dir)->offset(($page - 1) * $perPage)->limit($perPage)->get();

        $today = now()->toDateString();
        $base = SalesContract::whereNull('deleted_at')->where('company_id', $cid);

        return response()->json([
            'totalRows' => $total,
            'contracts' => $rows->map(fn ($c) => [
                'id' => $c->id,
                'contract_number' => $c->contract_number,
                'subject' => $c->subject,
                'client_id' => $c->client_id,
                'client_name' => $c->client->name ?? '',
                'value' => (float) $c->value,
                'type' => $c->type,
                'start_date' => $c->start_date,
                'end_date' => $c->end_date,
                'status' => $c->status,
                'billing_enabled' => (bool) $c->billing_enabled,
                'billing_frequency' => $c->billing_frequency,
                'billing_interval' => (int) $c->billing_interval,
                'billing_count' => $c->billing_count,
                'billing_day_mode' => $c->billing_day_mode,
                'billing_day' => $c->billing_day,
                'next_invoice_date' => $c->billing_enabled ? $c->next_invoice_date : null,
            ]),
            'stats' => [
                'active' => (clone $base)->where('status', 'active')->count(),
                'recurring' => (clone $base)->where('status', 'active')->where('billing_enabled', true)->count(),
                'due' => (clone $base)->where('status', 'active')->where('billing_enabled', true)->whereNotNull('next_invoice_date')->where('next_invoice_date', '<=', $today)->count(),
                'expiring' => (clone $base)->where('status', 'active')->whereNotNull('end_date')->whereBetween('end_date', [$today, now()->addDays(30)->toDateString()])->count(),
            ],
            'clients' => Client::whereNull('deleted_at')->where('company_id', $cid)
                ->whereIn('id', SalesContract::whereNull('deleted_at')->where('company_id', $cid)->select('client_id'))
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Form lists: the company's customers, locations and products/services. */
    private function lists(int $cid): array
    {
        return [
            'clients' => Client::whereNull('deleted_at')->where('company_id', $cid)->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::whereNull('deleted_at')->where('company_id', $cid)->orderBy('name')->get(['id', 'name']),
            'products' => Product::whereNull('deleted_at')->where('company_id', $cid)->orderBy('name')
                ->get(['id', 'name', 'code', 'price', 'type', 'TaxNet', 'tax_method']),
            'statuses' => SalesContract::STATUSES,
            'frequencies' => SalesContract::FREQUENCIES,
            // New contract's Order Tax: the company's Default Tax (Defaults).
            'default_tax' => (float) (Setting::forCompany($cid)->default_tax ?? 0),
            // Recurring service jobs (visits): only with the Service module on.
            'service_enabled' => \App\Support\ModuleFlags::enabled($cid, 'service'),
            'technicians' => ServiceTechnician::whereNull('deleted_at')->where('company_id', $cid)->where('is_active', true)
                ->orderBy('name')->get(['id', 'name']),
            // System Settings -> Defaults -> default Location: prefilled on a
            // new contract (only when it belongs to the company).
            'default_warehouse_id' => (function () use ($cid) {
                $id = (int) (Setting::forCompany($cid)->warehouse_id ?? 0);

                return $id && Warehouse::whereKey($id)->where('company_id', $cid)->whereNull('deleted_at')->exists() ? $id : null;
            })(),
        ];
    }

    /**
     * Recurring-invoice preview for the form (POST sales_contracts/schedule):
     * the invoices the current settings would create -- number, date, period
     * and each line's note filled in -- computed by the same code that
     * creates them (SalesContractBilling), so the preview can't drift.
     */
    public function schedulePreview(Request $request)
    {
        $user = $request->user('api');
        abort_unless($user, 401);
        if ($user->type !== 'SuperAdmin') {
            $perms = $user->roles()->first()?->permissions;
            abort_unless($perms && ($perms->contains('name', 'sales_contracts_add') || $perms->contains('name', 'sales_contracts_edit')), 403);
        }
        $v = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'nullable|date',
            'next_invoice_date' => 'nullable|date',
            'billing_frequency' => 'required|in:'.implode(',', SalesContract::FREQUENCIES),
            'billing_interval' => 'nullable|integer|min:1|max:24',
            'billing_count' => 'nullable|integer|min:1|max:999',
            'billing_day_mode' => 'nullable|in:'.implode(',', SalesContract::DAY_MODES),
            'billing_day' => 'nullable|integer|min:1|max:31',
            'notes' => 'array',
            'notes.*' => 'nullable|string|max:500',
            'invoice_note' => 'nullable|string|max:1000',
            'contract_id' => 'nullable|integer',
        ]);
        $s = $this->scheduleParams($v);
        $already = 0;
        if (! empty($v['contract_id'])) {
            $c = SalesContract::whereKey($v['contract_id'])->where('company_id', $this->companyId($request))->first();
            $already = $c ? SalesContractBilling::periodInvoiceCount($c) : 0;
        }
        $rows = SalesContractBilling::schedule(
            $s['first'], ! empty($v['end_date']) ? Carbon::parse($v['end_date']) : null,
            $s['frequency'], $s['interval'], $s['mode'], $s['day'], $s['count'], $already, 24
        );
        $notes = $v['notes'] ?? [];

        return response()->json([
            'first_invoice_date' => $s['first']->toDateString(),
            'billing_day_mode' => $s['mode'],
            'billing_day' => $s['day'],
            'open_ended' => ! $s['count'] && empty($v['end_date']),
            'invoices' => array_map(fn ($r) => [
                'number' => $r['number'],
                'date' => $r['date']->toDateString(),
                'period_start' => $r['start']->toDateString(),
                'period_end' => $r['end']->toDateString(),
                'notes' => array_map(fn ($t) => SalesContractBilling::renderNote($t, $r['start'], $r['end'], $r['number'], $s['count'], $r['date']), $notes),
                'invoice_note' => SalesContractBilling::renderNote($v['invoice_note'] ?? null, $r['start'], $r['end'], $r['number'], $s['count'], $r['date']),
            ], $rows),
        ]);
    }

    /**
     * Normalized recurring settings: "same day as the first invoice" is
     * stored as a fixed day (monthly types: that day of the month; weekly:
     * that weekday), so later invoices don't drift (Jan 31 -> Feb 28 -> Mar 28).
     * first = the first invoice date (the chosen date, else the start date),
     * moved forward to the first day that matches the rule.
     */
    private function scheduleParams(array $v): array
    {
        $frequency = $v['billing_frequency'] ?? 'monthly';
        $interval = (int) ($v['billing_interval'] ?? 1) ?: 1;
        $mode = $v['billing_day_mode'] ?? 'same';
        $day = ! empty($v['billing_day']) ? (int) $v['billing_day'] : null;
        $from = Carbon::parse($v['next_invoice_date'] ?? $v['start_date'])->startOfDay();
        if ($frequency === 'weekly' && in_array($mode, ['first', 'last'], true)) {
            $mode = 'same'; // month rules don't apply to weeks
        }
        if ($mode === 'day' && ! $day) {
            $mode = 'same';
        }
        if ($mode === 'day' && $frequency === 'weekly' && $day > 7) {
            $day = 7;
        }
        if ($mode === 'same') {
            $mode = 'day';
            $day = $frequency === 'weekly' ? $from->dayOfWeekIso : $from->day;
        }

        return [
            'frequency' => $frequency, 'interval' => $interval, 'mode' => $mode, 'day' => $day,
            'count' => ! empty($v['billing_count']) ? (int) $v['billing_count'] : null,
            'first' => SalesContractBilling::firstDate($from, $frequency, $interval, $mode, $day),
        ];
    }

    public function create(Request $request)
    {
        $this->need($request, 'sales_contracts_add');

        return response()->json($this->lists($this->companyId($request)) + ['next_contract_number' => SalesContract::generateNumber()]);
    }

    public function store(Request $request)
    {
        $this->need($request, 'sales_contracts_add');
        $cid = $this->companyId($request);
        $data = $this->validated($request, $cid);

        $contract = DB::transaction(function () use ($data, $cid, $request) {
            $c = SalesContract::create($data['contract'] + [
                'company_id' => $cid,
                'contract_number' => trim((string) $request->contract_number) ?: SalesContract::generateNumber(),
                'created_by' => $request->user('api')->id,
            ]);
            $this->saveItems($c, $data['items']);

            return $c;
        });

        // generate_due (the sale form's "Recurring" mode): create right away
        // every invoice whose date has already come (today or earlier), dated
        // on its own invoice date -- later ones follow from the daily run.
        $created = [];
        if ($request->boolean('generate_due') && $contract->billing_enabled && $contract->status === 'active') {
            $billing = app(SalesContractBilling::class);
            for ($i = 0; $i < 24; $i++) {
                $contract->refresh();
                if (! $contract->next_invoice_date || $contract->next_invoice_date > now()->toDateString()) {
                    break;
                }
                // Made now, while the user saves: final (later ones follow invoice_as_draft).
                $r = $billing->generate($contract, true, $request->user('api')->id, true);
                if (! $r['ok']) {
                    break;
                }
                $created[] = ['id' => $r['sale']->id, 'ref' => $r['sale']->Ref];
            }
        }

        return response()->json(['success' => true, 'id' => $contract->id, 'invoices_created' => $created, 'jobs_created' => $this->createDueJobs($contract, $request)]);
    }

    /** Visits already due (within the lead days) are created right away; later ones by the daily run. */
    private function createDueJobs(SalesContract $contract, Request $request): array
    {
        if (! $contract->jobs_enabled || $contract->status !== 'active') {
            return [];
        }

        return array_map(fn ($j) => ['id' => $j->id, 'ref' => $j->Ref],
            app(SalesContractJobs::class)->generateFor($contract, null, 12, $request->user('api')->id));
    }

    public function show(Request $request, $id)
    {
        $this->need($request, 'sales_contracts_view');
        $c = $this->find($request, $id)->load('client:id,name,email,phone', 'warehouse:id,name', 'items', 'invoices.sale:id,Ref,date,GrandTotal,paid_amount,payment_statut');

        return response()->json([
            'contract' => [
                'id' => $c->id,
                'contract_number' => $c->contract_number,
                'subject' => $c->subject,
                'type' => $c->type,
                'client' => $c->client,
                'warehouse' => $c->warehouse,
                'value' => (float) $c->value,
                'order_tax_rate' => (float) $c->order_tax_rate,
                'start_date' => $c->start_date,
                'end_date' => $c->end_date,
                'description' => $c->description,
                'invoice_note' => $c->invoice_note,
                'status' => $c->status,
                'hide_from_customer' => (bool) $c->hide_from_customer,
                'signer_name' => $c->signer_name,
                'signed_at' => $c->signed_at?->toIso8601String(),
                'billing_enabled' => (bool) $c->billing_enabled,
                'invoice_as_draft' => (bool) $c->invoice_as_draft,
                'billing_frequency' => $c->billing_frequency,
                'billing_interval' => (int) $c->billing_interval,
                'billing_count' => $c->billing_count,
                'billing_day_mode' => $c->billing_day_mode,
                'billing_day' => $c->billing_day,
                'invoices_created' => SalesContractBilling::periodInvoiceCount($c),
                'next_invoice_date' => $c->next_invoice_date,
                'last_invoice_date' => $c->last_invoice_date,
                'items' => $c->items->map(fn ($i) => [
                    'id' => $i->id, 'product_id' => $i->product_id, 'name' => $i->name, 'note' => $i->note, 'quantity' => (float) $i->quantity,
                    'price' => (float) $i->price, 'tax_percent' => (float) $i->tax_percent, 'tax_method' => $i->tax_method, 'total' => $i->total(),
                ]),
                'jobs_enabled' => (bool) $c->jobs_enabled,
                'jobs_service_item' => $c->jobs_service_item,
                'jobs_frequency' => $c->jobs_frequency,
                'jobs_interval' => (int) $c->jobs_interval,
                'jobs_count' => $c->jobs_count,
                'jobs_day_mode' => $c->jobs_day_mode,
                'jobs_day' => $c->jobs_day,
                'jobs_time' => $c->jobs_time,
                'jobs_lead_days' => (int) $c->jobs_lead_days,
                'jobs_technician' => $c->jobs_technician_id ? ServiceTechnician::whereKey($c->jobs_technician_id)->value('name') : null,
                'next_job_date' => $c->next_job_date,
                'jobs_created' => SalesContractJobs::createdCount($c),
                'service_jobs' => $c->serviceJobs()->whereNull('deleted_at')->with('technician:id,name')->get()->map(fn ($j) => [
                    'id' => $j->id, 'ref' => $j->Ref, 'number' => $j->contract_occurrence, 'title' => $j->service_item,
                    'date' => $j->scheduled_date ? Carbon::parse($j->scheduled_date)->toDateTimeString() : null,
                    'status' => $j->status, 'technician' => $j->technician->name ?? null,
                ]),
                'invoices' => $c->invoices->map(fn ($inv) => [
                    'id' => $inv->id, 'sale_id' => $inv->sale_id, 'ref' => $inv->sale->Ref ?? '(deleted)', 'date' => $inv->sale->date ?? null,
                    'total' => (float) ($inv->sale->GrandTotal ?? 0), 'paid' => (float) ($inv->sale->paid_amount ?? 0),
                    'payment_status' => $inv->sale->payment_statut ?? null, 'period_start' => $inv->period_start,
                    'period_end' => $inv->period_end, 'automatic' => (bool) $inv->automatic,
                ]),
            ],
        ]);
    }

    public function edit(Request $request, $id)
    {
        $this->need($request, 'sales_contracts_edit');
        $c = $this->find($request, $id)->load('items');

        return response()->json(['contract' => $c] + $this->lists($this->companyId($request)));
    }

    public function update(Request $request, $id)
    {
        $this->need($request, 'sales_contracts_edit');
        $cid = $this->companyId($request);
        $c = $this->find($request, $id);
        $data = $this->validated($request, $cid, $c);

        DB::transaction(function () use ($c, $data) {
            $c->update($data['contract']);
            $this->saveItems($c, $data['items']);
        });

        return response()->json(['success' => true, 'jobs_created' => $this->createDueJobs($c->fresh(), $request)]);
    }

    public function destroy(Request $request, $id)
    {
        $this->need($request, 'sales_contracts_delete');
        $this->find($request, $id)->delete();

        return response()->json(['success' => true]);
    }

    /** "Generate invoice now": the contract's current period (or a one-off invoice). */
    public function invoice(Request $request, $id, SalesContractBilling $billing)
    {
        $this->need($request, 'sales_contracts_invoice');
        $r = $billing->generate($this->find($request, $id), false, $request->user('api')->id);
        if (! $r['ok']) {
            return response()->json(['success' => false, 'message' => $r['reason']], 422);
        }

        return response()->json(['success' => true, 'sale_id' => $r['sale']->id, 'ref' => $r['sale']->Ref, 'total' => (float) $r['sale']->GrandTotal]);
    }

    private function validated(Request $request, int $cid, ?SalesContract $existing = null): array
    {
        $v = $request->validate([
            'client_id' => 'required|integer',
            'warehouse_id' => 'nullable|integer',
            'subject' => 'required|string|max:255',
            'type' => 'nullable|string|max:50',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'description' => 'nullable|string',
            'invoice_note' => 'nullable|string|max:1000',
            'status' => 'required|in:'.implode(',', SalesContract::STATUSES),
            'hide_from_customer' => 'nullable|boolean',
            'billing_enabled' => 'nullable|boolean',
            // Each recurring invoice is created as a draft (Pending) to review.
            'invoice_as_draft' => 'nullable|boolean',
            'billing_frequency' => 'nullable|in:'.implode(',', SalesContract::FREQUENCIES),
            'billing_interval' => 'nullable|integer|min:1|max:24',
            'billing_count' => 'nullable|integer|min:1|max:999',
            'billing_day_mode' => 'nullable|in:'.implode(',', SalesContract::DAY_MODES),
            'billing_day' => 'nullable|integer|min:1|max:31',
            'next_invoice_date' => 'nullable|date',
            'items' => 'array',
            'items.*.product_id' => 'nullable|integer',
            'items.*.name' => 'required|string|max:255',
            'items.*.note' => 'nullable|string|max:500',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.tax_percent' => 'nullable|numeric|min:0',
            'items.*.tax_method' => 'nullable|in:1,2',
            // Order Tax % on each invoice (like a sale's Order Tax).
            'order_tax_rate' => 'nullable|numeric|min:0|max:100',
            // Recurring service jobs (visits).
            'jobs_enabled' => 'nullable|boolean',
            'jobs_service_item' => 'nullable|string|max:191',
            'jobs_job_type' => 'nullable|string|max:191',
            'jobs_technician_id' => 'nullable|integer',
            'jobs_frequency' => 'nullable|in:'.implode(',', SalesContract::FREQUENCIES),
            'jobs_interval' => 'nullable|integer|min:1|max:24',
            'jobs_count' => 'nullable|integer|min:1|max:999',
            'jobs_day_mode' => 'nullable|in:'.implode(',', SalesContract::DAY_MODES),
            'jobs_day' => 'nullable|integer|min:1|max:31',
            'jobs_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'jobs_lead_days' => 'nullable|integer|min:0|max:60',
            'next_job_date' => 'nullable|date',
        ]);
        $jobs = (bool) ($v['jobs_enabled'] ?? false);
        abort_if($jobs && ! \App\Support\ModuleFlags::enabled($cid, 'service'), 422, 'Turn on the Service module for this company to create service jobs.');
        abort_if(! empty($v['jobs_technician_id']) && ! ServiceTechnician::whereKey($v['jobs_technician_id'])->where('company_id', $cid)->whereNull('deleted_at')->exists(), 422, 'That technician does not belong to this company.');
        if ($bad = SalesContractBilling::unknownTokens($v['jobs_service_item'] ?? null)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'jobs_service_item' => ['Unknown placeholder {{'.implode('}}, {{', $bad).'}} in the visit title. Use: {{'.implode('}}, {{', SalesContractBilling::NOTE_TOKENS).'}}.'],
            ]);
        }
        // Everything must belong to the acting company.
        abort_unless(Client::whereKey($v['client_id'])->where('company_id', $cid)->whereNull('deleted_at')->exists(), 422, 'That customer does not belong to this company.');
        abort_if(! empty($v['warehouse_id']) && ! Warehouse::whereKey($v['warehouse_id'])->where('company_id', $cid)->exists(), 422, 'That location does not belong to this company.');
        $productIds = collect($v['items'] ?? [])->pluck('product_id')->filter()->unique();
        abort_if($productIds->isNotEmpty() && Product::whereIn('id', $productIds)->where('company_id', $cid)->count() !== $productIds->count(), 422, 'A product does not belong to this company.');

        $billing = (bool) ($v['billing_enabled'] ?? false);
        abort_if($billing && empty($v['items']), 422, 'Recurring invoices need at least one line.');
        // Invoice + line notes: only the placeholders invoices can fill ({{month}}...).
        if ($bad = SalesContractBilling::unknownTokens($v['invoice_note'] ?? null)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'invoice_note' => ['Unknown placeholder {{'.implode('}}, {{', $bad).'}} in the invoice note. Use: {{'.implode('}}, {{', SalesContractBilling::NOTE_TOKENS).'}}.'],
            ]);
        }
        foreach ($v['items'] ?? [] as $i => $item) {
            if ($bad = SalesContractBilling::unknownTokens($item['note'] ?? null)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "items.$i.note" => ['Unknown placeholder {{'.implode('}}, {{', $bad).'}} in a line note. Use: {{'.implode('}}, {{', SalesContractBilling::NOTE_TOKENS).'}}.'],
                ]);
            }
        }
        $items = collect($v['items'] ?? [])->map(fn ($i) => [
            'product_id' => $i['product_id'] ?? null,
            'name' => $i['name'],
            'note' => isset($i['note']) && trim((string) $i['note']) !== '' ? trim((string) $i['note']) : null,
            'quantity' => (float) $i['quantity'],
            'price' => (float) $i['price'],
            'tax_percent' => (float) ($i['tax_percent'] ?? 0),
            'tax_method' => (string) ($i['tax_method'] ?? '1'),
        ])->all();
        // Value per invoice = the lines + the order tax on them.
        $dec = SalesContractBilling::decimals($cid);
        $orderRate = round((float) ($v['order_tax_rate'] ?? 0), 3);
        $linesTotal = collect($items)->sum(fn ($i) => (new SalesContractItem($i))->total($dec));
        $value = $linesTotal + ($orderRate > 0 ? round($linesTotal * $orderRate / 100, $dec) : 0);
        // Editing a contract whose invoices were all created (no next date)
        // and raising the count: continue after the last invoice, not from
        // the start date again.
        if ($billing && $existing && empty($v['next_invoice_date'])) {
            $lastStart = SalesContractInvoice::where('sales_contract_id', $existing->id)->whereNotNull('period_start')->max('period_start');
            if ($lastStart) {
                // period_start is the billed period's start; one period on
                // lands in the next period, which the day rule then aligns.
                $v['next_invoice_date'] = SalesContractBilling::advance(Carbon::parse($lastStart), $v['billing_frequency'] ?? 'monthly', (int) ($v['billing_interval'] ?? 1))->toDateString();
            }
        }
        $s = $billing ? $this->scheduleParams($v) : null;

        // Visits: the same schedule rules, on their own fields. Editing after
        // the last visit was created (no next date) continues after it.
        $jv = [
            'start_date' => $v['start_date'],
            'billing_frequency' => $v['jobs_frequency'] ?? 'weekly',
            'billing_interval' => $v['jobs_interval'] ?? 1,
            'billing_count' => $v['jobs_count'] ?? null,
            'billing_day_mode' => $v['jobs_day_mode'] ?? 'same',
            'billing_day' => $v['jobs_day'] ?? null,
            'next_invoice_date' => $v['next_job_date'] ?? null,
        ];
        if ($jobs && $existing && empty($jv['next_invoice_date'])) {
            $lastVisit = \App\Models\ServiceJob::where('sales_contract_id', $existing->id)->max('scheduled_date');
            if ($lastVisit) {
                $jv['next_invoice_date'] = SalesContractBilling::advance(Carbon::parse($lastVisit)->startOfDay(), $jv['billing_frequency'], (int) $jv['billing_interval'])->toDateString();
            }
        }
        $js = $jobs ? $this->scheduleParams($jv) : null;

        return [
            'contract' => [
                'client_id' => (int) $v['client_id'],
                'warehouse_id' => $v['warehouse_id'] ?? null,
                'subject' => $v['subject'],
                'type' => $v['type'] ?? null,
                'value' => round($value, $dec),
                'order_tax_rate' => $orderRate,
                'start_date' => $v['start_date'],
                'end_date' => $v['end_date'] ?? null,
                'description' => $v['description'] ?? null,
                'invoice_note' => isset($v['invoice_note']) && trim((string) $v['invoice_note']) !== '' ? trim((string) $v['invoice_note']) : null,
                'status' => $v['status'],
                'hide_from_customer' => (bool) ($v['hide_from_customer'] ?? false),
                'billing_enabled' => $billing,
                'invoice_as_draft' => (bool) ($v['invoice_as_draft'] ?? false),
                'billing_frequency' => $v['billing_frequency'] ?? 'monthly',
                'billing_interval' => (int) ($v['billing_interval'] ?? 1),
                'billing_count' => $s['count'] ?? (! empty($v['billing_count']) ? (int) $v['billing_count'] : null),
                'billing_day_mode' => $s['mode'] ?? ($v['billing_day_mode'] ?? 'same'),
                'billing_day' => $s['day'] ?? ($v['billing_day'] ?? null),
                // First / next invoice: the chosen date, else the contract
                // start -- moved onto the first day that matches the day rule.
                'next_invoice_date' => $s ? $s['first']->toDateString() : null,
                'jobs_enabled' => $jobs,
                'jobs_service_item' => isset($v['jobs_service_item']) && trim((string) $v['jobs_service_item']) !== '' ? trim((string) $v['jobs_service_item']) : null,
                'jobs_job_type' => $v['jobs_job_type'] ?? null,
                'jobs_technician_id' => $v['jobs_technician_id'] ?? null,
                'jobs_frequency' => $jv['billing_frequency'],
                'jobs_interval' => (int) $jv['billing_interval'],
                'jobs_count' => $js['count'] ?? (! empty($v['jobs_count']) ? (int) $v['jobs_count'] : null),
                'jobs_day_mode' => $js['mode'] ?? ($v['jobs_day_mode'] ?? 'same'),
                'jobs_day' => $js['day'] ?? ($v['jobs_day'] ?? null),
                // Empty = all-day visits.
                'jobs_time' => $v['jobs_time'] ?? null,
                'jobs_lead_days' => (int) ($v['jobs_lead_days'] ?? 7),
                // A count already reached: no next visit.
                'next_job_date' => $js && ! ($js['count'] && $existing && SalesContractJobs::createdCount($existing) >= $js['count'])
                    ? $js['first']->toDateString() : null,
            ],
            'items' => $items,
        ];
    }

    private function saveItems(SalesContract $c, array $items): void
    {
        $c->items()->delete();
        foreach ($items as $i) {
            $c->items()->create($i);
        }
    }
}
