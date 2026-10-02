<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Client;
use App\Models\Product;
use App\Models\SalesContract;
use App\Models\SalesContractItem;
use App\Models\Warehouse;
use App\Services\SalesContractBilling;
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

        return response()->json(['success' => true, 'id' => $contract->id]);
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
                'start_date' => $c->start_date,
                'end_date' => $c->end_date,
                'description' => $c->description,
                'status' => $c->status,
                'hide_from_customer' => (bool) $c->hide_from_customer,
                'signer_name' => $c->signer_name,
                'signed_at' => $c->signed_at?->toIso8601String(),
                'billing_enabled' => (bool) $c->billing_enabled,
                'billing_frequency' => $c->billing_frequency,
                'billing_interval' => (int) $c->billing_interval,
                'next_invoice_date' => $c->next_invoice_date,
                'last_invoice_date' => $c->last_invoice_date,
                'items' => $c->items->map(fn ($i) => [
                    'id' => $i->id, 'product_id' => $i->product_id, 'name' => $i->name, 'quantity' => (float) $i->quantity,
                    'price' => (float) $i->price, 'tax_percent' => (float) $i->tax_percent, 'tax_method' => $i->tax_method, 'total' => $i->total(),
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
        $data = $this->validated($request, $cid);

        DB::transaction(function () use ($c, $data) {
            $c->update($data['contract']);
            $this->saveItems($c, $data['items']);
        });

        return response()->json(['success' => true]);
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

    private function validated(Request $request, int $cid): array
    {
        $v = $request->validate([
            'client_id' => 'required|integer',
            'warehouse_id' => 'nullable|integer',
            'subject' => 'required|string|max:255',
            'type' => 'nullable|string|max:50',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'description' => 'nullable|string',
            'status' => 'required|in:'.implode(',', SalesContract::STATUSES),
            'hide_from_customer' => 'nullable|boolean',
            'billing_enabled' => 'nullable|boolean',
            'billing_frequency' => 'nullable|in:'.implode(',', SalesContract::FREQUENCIES),
            'billing_interval' => 'nullable|integer|min:1|max:24',
            'next_invoice_date' => 'nullable|date',
            'items' => 'array',
            'items.*.product_id' => 'nullable|integer',
            'items.*.name' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.tax_percent' => 'nullable|numeric|min:0',
            'items.*.tax_method' => 'nullable|in:1,2',
        ]);
        // Everything must belong to the acting company.
        abort_unless(Client::whereKey($v['client_id'])->where('company_id', $cid)->whereNull('deleted_at')->exists(), 422, 'That customer does not belong to this company.');
        abort_if(! empty($v['warehouse_id']) && ! Warehouse::whereKey($v['warehouse_id'])->where('company_id', $cid)->exists(), 422, 'That location does not belong to this company.');
        $productIds = collect($v['items'] ?? [])->pluck('product_id')->filter()->unique();
        abort_if($productIds->isNotEmpty() && Product::whereIn('id', $productIds)->where('company_id', $cid)->count() !== $productIds->count(), 422, 'A product does not belong to this company.');

        $billing = (bool) ($v['billing_enabled'] ?? false);
        abort_if($billing && empty($v['items']), 422, 'Recurring invoices need at least one line.');
        $items = collect($v['items'] ?? [])->map(fn ($i) => [
            'product_id' => $i['product_id'] ?? null,
            'name' => $i['name'],
            'quantity' => (float) $i['quantity'],
            'price' => (float) $i['price'],
            'tax_percent' => (float) ($i['tax_percent'] ?? 0),
            'tax_method' => (string) ($i['tax_method'] ?? '1'),
        ])->all();
        $value = collect($items)->sum(fn ($i) => (new SalesContractItem($i))->total());

        return [
            'contract' => [
                'client_id' => (int) $v['client_id'],
                'warehouse_id' => $v['warehouse_id'] ?? null,
                'subject' => $v['subject'],
                'type' => $v['type'] ?? null,
                'value' => round($value, 2),
                'start_date' => $v['start_date'],
                'end_date' => $v['end_date'] ?? null,
                'description' => $v['description'] ?? null,
                'status' => $v['status'],
                'hide_from_customer' => (bool) ($v['hide_from_customer'] ?? false),
                'billing_enabled' => $billing,
                'billing_frequency' => $v['billing_frequency'] ?? 'monthly',
                'billing_interval' => (int) ($v['billing_interval'] ?? 1),
                // First invoice: the chosen date, else the contract start.
                'next_invoice_date' => $billing ? ($v['next_invoice_date'] ?? $v['start_date']) : null,
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
