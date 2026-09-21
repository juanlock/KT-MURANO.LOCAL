<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\HospitalInvoice;
use App\Models\HospitalInvoiceItem;
use App\Models\HospitalPayment;
use App\Models\LabOrder;
use App\Models\PatientVisit;
use App\Models\Prescription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Hospital billing: invoices, their lines, and payments against them.
 *
 * Money rules kept in one place on purpose:
 *   - line totals, the subtotal and the invoice total are RECOMPUTED on save,
 *     never trusted from the client;
 *   - `paid` is the sum of the payment rows, never typed in;
 *   - the status is derived from those two by HospitalInvoice::syncStatus().
 * That means a bill cannot be marked paid without a payment existing to explain
 * it, which is the property an audit actually needs.
 */
class HospitalBillingController extends Controller
{
    private const SORTABLE = ['id', 'reference', 'invoice_date', 'due_date', 'total', 'paid', 'status', 'created_at'];

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', HospitalInvoice::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = in_array($request->SortField, self::SORTABLE, true) ? $request->SortField : 'invoice_date';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = HospitalInvoice::with('patient')->whereNull('deleted_at');

        if ($request->filled('patient_id')) {
            $query->where('patient_id', $request->patient_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->boolean('outstanding')) {
            $query->whereIn('status', ['unpaid', 'partial']);
        }
        if ($request->filled('start_date')) {
            $query->whereDate('invoice_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('invoice_date', '<=', $request->end_date);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'LIKE', "%{$search}%")
                    ->orWhereHas('patient', fn ($p) => $p->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('mrn', 'LIKE', "%{$search}%"));
            });
        }

        $totalRows = $query->count();
        $totals = [
            'billed' => round((float) $query->sum('total'), 2),
            'paid' => round((float) $query->sum('paid'), 2),
        ];
        $totals['due'] = round($totals['billed'] - $totals['paid'], 2);

        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $offset = ($page * $perPage) - $perPage;

        $rows = $query->orderBy($order, $dir)->offset($offset)->limit($perPage)->get();

        return response()->json([
            'totalRows' => $totalRows,
            'totals' => $totals,
            'invoices' => $rows->map(fn ($i) => $this->present($i))->values(),
        ]);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', HospitalInvoice::class);

        $invoice = HospitalInvoice::with('patient', 'items', 'payments')->whereNull('deleted_at')->findOrFail($id);

        $data = $this->present($invoice);
        $data['items'] = $invoice->items->map(fn ($i) => [
            'id' => $i->id,
            'type' => $i->type,
            'product_id' => $i->product_id,
            'description' => $i->description,
            'quantity' => (float) $i->quantity,
            'unit_price' => (float) $i->unit_price,
            'total' => (float) $i->total,
        ])->values();
        $data['payments'] = $invoice->payments->sortByDesc('paid_on')->map(fn ($p) => [
            'id' => $p->id,
            'reference' => $p->reference,
            'paid_on' => optional($p->paid_on)->toDateString(),
            'amount' => (float) $p->amount,
            'method' => $p->method,
            'notes' => $p->notes,
        ])->values();

        return response()->json(['invoice' => $data]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', HospitalInvoice::class);

        $request->validate($this->rules());

        $userId = optional($request->user('api'))->id;

        $invoice = DB::transaction(function () use ($request, $userId) {
            $invoice = HospitalInvoice::create([
                'reference' => HospitalInvoice::nextReference('INV'),
                'patient_id' => $request->patient_id,
                'visit_id' => $request->visit_id ?: null,
                'admission_id' => $request->admission_id ?: null,
                'lab_order_id' => $request->lab_order_id ?: null,
                'invoice_date' => $request->invoice_date ?: now()->toDateString(),
                'due_date' => $request->due_date ?: null,
                'discount' => $request->discount ?: 0,
                'tax' => $request->tax ?: 0,
                'status' => $request->status ?: 'unpaid',
                'notes' => $request->notes,
                'created_by' => $userId,
            ]);

            $this->syncItems($invoice, $request->input('items'));
            $this->recalculate($invoice);

            return $invoice;
        });

        return response()->json(['success' => true, 'id' => $invoice->id, 'reference' => $invoice->reference]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', HospitalInvoice::class);

        $invoice = HospitalInvoice::whereNull('deleted_at')->findOrFail($id);
        $request->validate($this->rules());

        DB::transaction(function () use ($request, $invoice) {
            $invoice->update([
                'patient_id' => $request->patient_id,
                'invoice_date' => $request->invoice_date ?: $invoice->invoice_date,
                'due_date' => $request->due_date ?: null,
                'discount' => $request->discount ?: 0,
                'tax' => $request->tax ?: 0,
                'status' => $request->status ?: $invoice->status,
                'notes' => $request->notes,
            ]);

            $this->syncItems($invoice, $request->input('items'));
            $this->recalculate($invoice);
        });

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', HospitalInvoice::class);

        $invoice = HospitalInvoice::whereNull('deleted_at')->findOrFail($id);

        if ($invoice->payments()->whereNull('deleted_at')->exists()) {
            return response()->json([
                'message' => 'This invoice has payments recorded against it. Reverse those first, or cancel the invoice instead.',
            ], 422);
        }

        DB::transaction(function () use ($invoice) {
            HospitalInvoiceItem::where('invoice_id', $invoice->id)->delete();
            $invoice->delete();
        });

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------
    // Payments
    // ------------------------------------------------------------------

    public function storePayment(Request $request, $invoiceId)
    {
        $this->authorizeForUser($request->user('api'), 'update', HospitalInvoice::class);

        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'paid_on' => 'nullable|date',
            'method' => 'nullable|string|max:64',
        ]);

        $invoice = HospitalInvoice::whereNull('deleted_at')->findOrFail($invoiceId);

        if ($invoice->status === 'cancelled') {
            return response()->json(['message' => 'This invoice is cancelled.'], 422);
        }

        // Overpayment is refused rather than silently absorbed: a credit that
        // exists nowhere in the ledger is how reconciliations go wrong.
        $due = $invoice->due;
        if ((float) $request->amount > $due + 0.001) {
            return response()->json([
                'message' => 'That is more than the outstanding balance (' . number_format($due, 2) . ').',
            ], 422);
        }

        DB::transaction(function () use ($request, $invoice) {
            HospitalPayment::create([
                'reference' => HospitalPayment::nextReference('PAY'),
                'invoice_id' => $invoice->id,
                'patient_id' => $invoice->patient_id,
                'paid_on' => $request->paid_on ?: now()->toDateString(),
                'amount' => $request->amount,
                'method' => $request->method ?: 'cash',
                'notes' => $request->notes,
                'created_by' => optional($request->user('api'))->id,
            ]);

            $this->recalculate($invoice);
        });

        return response()->json(['success' => true, 'due' => $invoice->fresh()->due]);
    }

    public function destroyPayment(Request $request, $invoiceId, $paymentId)
    {
        $this->authorizeForUser($request->user('api'), 'delete', HospitalInvoice::class);

        $invoice = HospitalInvoice::whereNull('deleted_at')->findOrFail($invoiceId);
        $payment = HospitalPayment::where('invoice_id', $invoice->id)->findOrFail($paymentId);

        DB::transaction(function () use ($payment, $invoice) {
            $payment->delete();
            $this->recalculate($invoice);
        });

        return response()->json(['success' => true]);
    }

    public function payments(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', HospitalInvoice::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));

        $query = HospitalPayment::with('patient', 'invoice')->whereNull('deleted_at');

        if ($request->filled('patient_id')) {
            $query->where('patient_id', $request->patient_id);
        }
        if ($request->filled('method')) {
            $query->where('method', $request->method);
        }
        if ($request->filled('start_date')) {
            $query->whereDate('paid_on', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('paid_on', '<=', $request->end_date);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'LIKE', "%{$search}%")
                    ->orWhereHas('patient', fn ($p) => $p->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('mrn', 'LIKE', "%{$search}%"));
            });
        }

        $totalRows = $query->count();
        $collected = round((float) $query->sum('amount'), 2);
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $offset = ($page * $perPage) - $perPage;

        $rows = $query->orderBy('paid_on', 'desc')->offset($offset)->limit($perPage)->get();

        return response()->json([
            'totalRows' => $totalRows,
            'collected' => $collected,
            'payments' => $rows->map(fn ($p) => [
                'id' => $p->id,
                'reference' => $p->reference,
                'invoice_id' => $p->invoice_id,
                'invoice_reference' => $p->invoice ? $p->invoice->reference : null,
                'patient_id' => $p->patient_id,
                'patient_name' => $p->patient ? $p->patient->name : '',
                'paid_on' => optional($p->paid_on)->toDateString(),
                'amount' => (float) $p->amount,
                'method' => $p->method,
                'notes' => $p->notes,
            ])->values(),
        ]);
    }

    // ------------------------------------------------------------------
    // Build a bill from an episode of care
    // ------------------------------------------------------------------

    /**
     * Draft invoice lines for a visit, admission or lab order — consultation
     * fee, prescribed medicines, tests and bed nights, priced from the source
     * records so nobody retypes them.
     */
    public function draftFrom(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', HospitalInvoice::class);

        $request->validate([
            'source' => 'required|in:visit,admission,lab_order',
            'id' => 'required|integer',
        ]);

        $items = [];
        $patientId = null;
        $links = ['visit_id' => null, 'admission_id' => null, 'lab_order_id' => null];

        if ($request->source === 'visit') {
            $visit = PatientVisit::with('doctor')->whereNull('deleted_at')->findOrFail($request->id);
            $patientId = $visit->patient_id;
            $links['visit_id'] = $visit->id;

            if ((float) $visit->fee > 0) {
                $items[] = $this->line('consultation', 'Consultation — ' . ($visit->doctor ? $visit->doctor->name : $visit->reference), 1, (float) $visit->fee);
            }

            $prescription = Prescription::with('items.product')->whereNull('deleted_at')
                ->where('visit_id', $visit->id)->first();
            if ($prescription) {
                foreach ($prescription->items as $item) {
                    // Price from the pharmacy catalogue when the drug is stocked.
                    $price = $item->product ? (float) $item->product->price : 0;
                    $items[] = $this->line('medicine', $item->medicine, (float) $item->quantity ?: 1, $price, $item->product_id);
                }
            }
        } elseif ($request->source === 'admission') {
            $admission = Admission::with('ward', 'bed')->whereNull('deleted_at')->findOrFail($request->id);
            $patientId = $admission->patient_id;
            $links['admission_id'] = $admission->id;

            $label = 'Bed charge — ' . ($admission->ward ? $admission->ward->name : 'ward')
                . ($admission->bed ? ' / ' . $admission->bed->bed_number : '');
            $items[] = $this->line('bed', $label, $admission->nights, (float) $admission->daily_rate);
        } else {
            $order = LabOrder::with('items')->whereNull('deleted_at')->findOrFail($request->id);
            $patientId = $order->patient_id;
            $links['lab_order_id'] = $order->id;

            foreach ($order->items as $item) {
                $items[] = $this->line('lab', $item->test_name, 1, (float) $item->price);
            }
        }

        return response()->json([
            'draft' => array_merge($links, [
                'patient_id' => $patientId,
                'invoice_date' => now()->toDateString(),
                'items' => $items,
            ]),
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function line($type, $description, $quantity, $unitPrice, $productId = null)
    {
        return [
            'type' => $type,
            'product_id' => $productId,
            'description' => $description,
            'quantity' => round((float) $quantity, 2),
            'unit_price' => round((float) $unitPrice, 2),
            'total' => round((float) $quantity * (float) $unitPrice, 2),
        ];
    }

    private function syncItems(HospitalInvoice $invoice, $items)
    {
        $items = is_string($items) ? json_decode($items, true) : $items;
        $items = is_array($items) ? $items : [];

        HospitalInvoiceItem::where('invoice_id', $invoice->id)->delete();

        foreach ($items as $item) {
            if (empty($item['description'])) {
                continue;
            }
            $quantity = (float) ($item['quantity'] ?? 1);
            $unitPrice = (float) ($item['unit_price'] ?? 0);

            HospitalInvoiceItem::create([
                'invoice_id' => $invoice->id,
                'type' => $item['type'] ?? 'other',
                'product_id' => $item['product_id'] ?? null,
                'description' => $item['description'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                // Recomputed, never taken from the client.
                'total' => round($quantity * $unitPrice, 2),
            ]);
        }
    }

    /** Recompute subtotal/total/paid and re-derive the status. */
    private function recalculate(HospitalInvoice $invoice)
    {
        $subtotal = (float) HospitalInvoiceItem::where('invoice_id', $invoice->id)->sum('total');
        $paid = (float) HospitalPayment::where('invoice_id', $invoice->id)->whereNull('deleted_at')->sum('amount');

        $invoice->subtotal = round($subtotal, 2);
        $invoice->total = round(max(0, $subtotal - (float) $invoice->discount + (float) $invoice->tax), 2);
        $invoice->paid = round($paid, 2);
        $invoice->syncStatus()->save();

        return $invoice;
    }

    private function present(HospitalInvoice $i)
    {
        return [
            'id' => $i->id,
            'reference' => $i->reference,
            'patient_id' => $i->patient_id,
            'patient_name' => $i->patient ? $i->patient->name : '',
            'patient_mrn' => $i->patient ? $i->patient->mrn : '',
            'visit_id' => $i->visit_id,
            'admission_id' => $i->admission_id,
            'lab_order_id' => $i->lab_order_id,
            'invoice_date' => optional($i->invoice_date)->toDateString(),
            'due_date' => optional($i->due_date)->toDateString(),
            'subtotal' => (float) $i->subtotal,
            'discount' => (float) $i->discount,
            'tax' => (float) $i->tax,
            'total' => (float) $i->total,
            'paid' => (float) $i->paid,
            'due' => $i->due,
            'status' => $i->status,
            'notes' => $i->notes,
        ];
    }

    private function rules()
    {
        return [
            'patient_id' => 'required|exists:patients,id',
            'visit_id' => 'nullable|exists:patient_visits,id',
            'admission_id' => 'nullable|exists:admissions,id',
            'lab_order_id' => 'nullable|exists:lab_orders,id',
            'invoice_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'discount' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:draft,unpaid,partial,paid,cancelled',
            'items' => 'required|array|min:1',
        ];
    }
}
