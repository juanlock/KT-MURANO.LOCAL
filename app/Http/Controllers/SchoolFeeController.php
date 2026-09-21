<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\FeeStructure;
use App\Models\SchoolInvoice;
use App\Models\SchoolInvoiceItem;
use App\Models\SchoolPayment;
use App\Models\Student;
use App\Models\StudentEnrollment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Fees: the structures a school charges, the invoices raised from them, and the
 * payments received.
 *
 * Money rules kept in one place, deliberately:
 *   - line totals and the invoice total are RECOMPUTED on save, never trusted
 *     from the client;
 *   - `paid` is the sum of the payment rows, never typed in;
 *   - the status is derived from those two by SchoolInvoice::syncStatus().
 * A bill therefore cannot be marked paid without a payment existing to explain
 * it, which is the property a bursar's audit actually needs.
 */
class SchoolFeeController extends Controller
{
    private const SORTABLE = ['id', 'reference', 'invoice_date', 'due_date', 'total', 'paid', 'status', 'created_at'];

    // ------------------------------------------------------------------
    // Fee structures
    // ------------------------------------------------------------------

    public function structures(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', SchoolInvoice::class);

        $yearId = $request->academic_year_id ?: optional(AcademicYear::current())->id;

        $rows = FeeStructure::with('schoolClass', 'academicYear')->whereNull('deleted_at')
            ->when($yearId, fn ($q) => $q->where('academic_year_id', $yearId))
            ->when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->class_id))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'LIKE', "%{$request->search}%"))
            ->orderBy('class_id')->orderBy('name')->get();

        return response()->json([
            'totalRows' => $rows->count(),
            'fee_structures' => $rows->map(fn ($f) => [
                'id' => $f->id,
                'academic_year_id' => $f->academic_year_id,
                'year_name' => $f->academicYear ? $f->academicYear->name : '',
                'class_id' => $f->class_id,
                // Null class means it applies to the whole school.
                'class_name' => $f->schoolClass ? $f->schoolClass->name : 'All classes',
                'name' => $f->name,
                'frequency' => $f->frequency,
                'amount' => (float) $f->amount,
                'due_date' => optional($f->due_date)->toDateString(),
                'is_optional' => (bool) $f->is_optional,
                'is_active' => (bool) $f->is_active,
                'description' => $f->description,
            ])->values(),
        ]);
    }

    public function storeStructure(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', SchoolInvoice::class);

        $request->validate($this->structureRules());
        FeeStructure::create($this->structurePayload($request));

        return response()->json(['success' => true]);
    }

    public function updateStructure(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', SchoolInvoice::class);

        $request->validate($this->structureRules());
        FeeStructure::whereNull('deleted_at')->findOrFail($id)->update($this->structurePayload($request));

        return response()->json(['success' => true]);
    }

    public function destroyStructure(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', SchoolInvoice::class);

        FeeStructure::whereNull('deleted_at')->findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------
    // Invoices
    // ------------------------------------------------------------------

    public function invoices(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', SchoolInvoice::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = in_array($request->SortField, self::SORTABLE, true) ? $request->SortField : 'invoice_date';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = SchoolInvoice::with('student')->whereNull('deleted_at')
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->student_id))
            ->when($request->filled('class_id'), fn ($q) => $q->where('class_id', $request->class_id))
            ->when($request->filled('academic_year_id'), fn ($q) => $q->where('academic_year_id', $request->academic_year_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->boolean('outstanding'), fn ($q) => $q->whereIn('status', ['unpaid', 'partial']))
            ->when($request->boolean('overdue'), fn ($q) => $q->whereIn('status', ['unpaid', 'partial'])
                ->whereNotNull('due_date')->whereDate('due_date', '<', now()->toDateString()))
            ->when($request->filled('start_date'), fn ($q) => $q->whereDate('invoice_date', '>=', $request->start_date))
            ->when($request->filled('end_date'), fn ($q) => $q->whereDate('invoice_date', '<=', $request->end_date))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->search;
                $q->where(function ($inner) use ($search) {
                    $inner->where('reference', 'LIKE', "%{$search}%")
                        ->orWhereHas('student', fn ($s) => $s->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('admission_number', 'LIKE', "%{$search}%"));
                });
            });

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

    public function showInvoice(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', SchoolInvoice::class);

        $invoice = SchoolInvoice::with('student', 'items', 'payments')->whereNull('deleted_at')->findOrFail($id);

        $data = $this->present($invoice);
        $data['items'] = $invoice->items->map(fn ($i) => [
            'id' => $i->id,
            'fee_structure_id' => $i->fee_structure_id,
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

    public function storeInvoice(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', SchoolInvoice::class);

        $request->validate($this->invoiceRules());

        $userId = optional($request->user('api'))->id;

        $invoice = DB::transaction(function () use ($request, $userId) {
            $invoice = SchoolInvoice::create([
                'reference' => SchoolInvoice::nextReference('FEE'),
                'student_id' => $request->student_id,
                'academic_year_id' => $request->academic_year_id ?: optional(AcademicYear::current())->id,
                'class_id' => $request->class_id ?: null,
                'invoice_date' => $request->invoice_date ?: now()->toDateString(),
                'due_date' => $request->due_date ?: null,
                'discount' => $request->discount ?: 0,
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

    public function updateInvoice(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', SchoolInvoice::class);

        $request->validate($this->invoiceRules());
        $invoice = SchoolInvoice::whereNull('deleted_at')->findOrFail($id);

        DB::transaction(function () use ($request, $invoice) {
            $invoice->update([
                'student_id' => $request->student_id,
                'class_id' => $request->class_id ?: null,
                'invoice_date' => $request->invoice_date ?: $invoice->invoice_date,
                'due_date' => $request->due_date ?: null,
                'discount' => $request->discount ?: 0,
                'status' => $request->status ?: $invoice->status,
                'notes' => $request->notes,
            ]);

            $this->syncItems($invoice, $request->input('items'));
            $this->recalculate($invoice);
        });

        return response()->json(['success' => true]);
    }

    public function destroyInvoice(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', SchoolInvoice::class);

        $invoice = SchoolInvoice::whereNull('deleted_at')->findOrFail($id);

        if ($invoice->payments()->whereNull('deleted_at')->exists()) {
            return response()->json([
                'message' => 'This invoice has payments against it. Reverse those first, or cancel the invoice instead.',
            ], 422);
        }

        DB::transaction(function () use ($invoice) {
            SchoolInvoiceItem::where('invoice_id', $invoice->id)->delete();
            $invoice->delete();
        });

        return response()->json(['success' => true]);
    }

    /**
     * Raise the term's fees for a whole class in one go.
     *
     * Students who already hold an invoice for that year with the same lines are
     * skipped, so running it twice does not double-bill a family — the single
     * most damaging mistake this screen could make.
     */
    public function generateInvoices(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', SchoolInvoice::class);

        $request->validate([
            'academic_year_id' => 'required|exists:academic_years,id',
            'class_id' => 'required|exists:school_classes,id',
            'fee_structure_ids' => 'required|array|min:1',
            'invoice_date' => 'nullable|date',
            'due_date' => 'nullable|date',
        ]);

        $structures = FeeStructure::whereNull('deleted_at')
            ->whereIn('id', $request->fee_structure_ids)->get();
        if ($structures->isEmpty()) {
            return response()->json(['message' => 'None of those fee items exist.'], 422);
        }

        $enrollments = StudentEnrollment::whereNull('deleted_at')
            ->where('academic_year_id', $request->academic_year_id)
            ->where('class_id', $request->class_id)
            ->where('status', 'active')
            ->when($request->filled('section_id'), fn ($q) => $q->where('section_id', $request->section_id))
            ->get();

        if ($enrollments->isEmpty()) {
            return response()->json(['message' => 'No active students are enrolled in that class.'], 422);
        }

        $signature = $structures->pluck('id')->sort()->implode(',');
        $userId = optional($request->user('api'))->id;
        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($enrollments, $structures, $signature, $request, $userId, &$created, &$skipped) {
            foreach ($enrollments as $enrollment) {
                // Same student + year + same set of fee items = already billed.
                $duplicate = SchoolInvoice::whereNull('deleted_at')
                    ->where('student_id', $enrollment->student_id)
                    ->where('academic_year_id', $request->academic_year_id)
                    ->get()
                    ->first(function ($invoice) use ($signature) {
                        $ids = SchoolInvoiceItem::where('invoice_id', $invoice->id)
                            ->whereNotNull('fee_structure_id')
                            ->pluck('fee_structure_id')->sort()->implode(',');

                        return $ids !== '' && $ids === $signature;
                    });

                if ($duplicate) {
                    $skipped++;
                    continue;
                }

                $invoice = SchoolInvoice::create([
                    'reference' => SchoolInvoice::nextReference('FEE'),
                    'student_id' => $enrollment->student_id,
                    'academic_year_id' => $request->academic_year_id,
                    'class_id' => $request->class_id,
                    'invoice_date' => $request->invoice_date ?: now()->toDateString(),
                    'due_date' => $request->due_date ?: null,
                    'status' => 'unpaid',
                    'created_by' => $userId,
                ]);

                foreach ($structures as $structure) {
                    SchoolInvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'fee_structure_id' => $structure->id,
                        'description' => $structure->name,
                        'quantity' => 1,
                        'unit_price' => $structure->amount,
                        'total' => $structure->amount,
                    ]);
                }

                $this->recalculate($invoice);
                $created++;
            }
        });

        return response()->json(['success' => true, 'created' => $created, 'skipped' => $skipped]);
    }

    // ------------------------------------------------------------------
    // Payments
    // ------------------------------------------------------------------

    public function storePayment(Request $request, $invoiceId)
    {
        $this->authorizeForUser($request->user('api'), 'update', SchoolInvoice::class);

        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'paid_on' => 'nullable|date',
            'method' => 'nullable|string|max:64',
        ]);

        $invoice = SchoolInvoice::whereNull('deleted_at')->findOrFail($invoiceId);
        if ($invoice->status === 'cancelled') {
            return response()->json(['message' => 'This invoice is cancelled.'], 422);
        }

        // Overpayment is refused rather than absorbed: a credit that exists
        // nowhere in the ledger is how reconciliations go wrong.
        $due = $invoice->due;
        if ((float) $request->amount > $due + 0.001) {
            return response()->json([
                'message' => 'That is more than the outstanding balance (' . number_format($due, 2) . ').',
            ], 422);
        }

        DB::transaction(function () use ($request, $invoice) {
            SchoolPayment::create([
                'reference' => SchoolPayment::nextReference('SPY'),
                'invoice_id' => $invoice->id,
                'student_id' => $invoice->student_id,
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
        $this->authorizeForUser($request->user('api'), 'delete', SchoolInvoice::class);

        $invoice = SchoolInvoice::whereNull('deleted_at')->findOrFail($invoiceId);
        $payment = SchoolPayment::where('invoice_id', $invoice->id)->findOrFail($paymentId);

        DB::transaction(function () use ($payment, $invoice) {
            $payment->delete();
            $this->recalculate($invoice);
        });

        return response()->json(['success' => true]);
    }

    public function payments(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', SchoolInvoice::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));

        $query = SchoolPayment::with('student', 'invoice')->whereNull('deleted_at')
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->student_id))
            ->when($request->filled('method'), fn ($q) => $q->where('method', $request->method))
            ->when($request->filled('start_date'), fn ($q) => $q->whereDate('paid_on', '>=', $request->start_date))
            ->when($request->filled('end_date'), fn ($q) => $q->whereDate('paid_on', '<=', $request->end_date))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->search;
                $q->where(function ($inner) use ($search) {
                    $inner->where('reference', 'LIKE', "%{$search}%")
                        ->orWhereHas('student', fn ($s) => $s->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('admission_number', 'LIKE', "%{$search}%"));
                });
            });

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
                'student_id' => $p->student_id,
                'student_name' => $p->student ? $p->student->name : '',
                'admission_number' => $p->student ? $p->student->admission_number : '',
                'paid_on' => optional($p->paid_on)->toDateString(),
                'amount' => (float) $p->amount,
                'method' => $p->method,
                'notes' => $p->notes,
            ])->values(),
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function syncItems(SchoolInvoice $invoice, $items)
    {
        $items = is_string($items) ? json_decode($items, true) : $items;
        $items = is_array($items) ? $items : [];

        SchoolInvoiceItem::where('invoice_id', $invoice->id)->delete();

        foreach ($items as $item) {
            if (empty($item['description'])) {
                continue;
            }
            $quantity = (float) ($item['quantity'] ?? 1);
            $unitPrice = (float) ($item['unit_price'] ?? 0);

            SchoolInvoiceItem::create([
                'invoice_id' => $invoice->id,
                'fee_structure_id' => $item['fee_structure_id'] ?? null,
                'description' => $item['description'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                // Recomputed, never taken from the client.
                'total' => round($quantity * $unitPrice, 2),
            ]);
        }
    }

    private function recalculate(SchoolInvoice $invoice)
    {
        $subtotal = (float) SchoolInvoiceItem::where('invoice_id', $invoice->id)->sum('total');
        $paid = (float) SchoolPayment::where('invoice_id', $invoice->id)->whereNull('deleted_at')->sum('amount');

        $invoice->subtotal = round($subtotal, 2);
        $invoice->total = round(max(0, $subtotal - (float) $invoice->discount), 2);
        $invoice->paid = round($paid, 2);
        $invoice->syncStatus()->save();

        return $invoice;
    }

    private function present(SchoolInvoice $i)
    {
        return [
            'id' => $i->id,
            'reference' => $i->reference,
            'student_id' => $i->student_id,
            'student_name' => $i->student ? $i->student->name : '',
            'admission_number' => $i->student ? $i->student->admission_number : '',
            'academic_year_id' => $i->academic_year_id,
            'class_id' => $i->class_id,
            'invoice_date' => optional($i->invoice_date)->toDateString(),
            'due_date' => optional($i->due_date)->toDateString(),
            'is_overdue' => $i->due_date && $i->due > 0 && $i->due_date->isPast(),
            'subtotal' => (float) $i->subtotal,
            'discount' => (float) $i->discount,
            'total' => (float) $i->total,
            'paid' => (float) $i->paid,
            'due' => $i->due,
            'status' => $i->status,
            'notes' => $i->notes,
        ];
    }

    private function structureRules()
    {
        return [
            'academic_year_id' => 'required|exists:academic_years,id',
            'class_id' => 'nullable|exists:school_classes,id',
            'name' => 'required|string|max:191',
            'frequency' => 'required|in:once,monthly,termly,yearly',
            'amount' => 'required|numeric|min:0',
            'due_date' => 'nullable|date',
        ];
    }

    private function structurePayload(Request $request)
    {
        return [
            'academic_year_id' => $request->academic_year_id,
            'class_id' => $request->class_id ?: null,
            'name' => $request->name,
            'frequency' => $request->frequency,
            'amount' => $request->amount,
            'due_date' => $request->due_date ?: null,
            'is_optional' => $request->boolean('is_optional'),
            'is_active' => $request->boolean('is_active', true),
            'description' => $request->description,
        ];
    }

    private function invoiceRules()
    {
        return [
            'student_id' => 'required|exists:students,id',
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'class_id' => 'nullable|exists:school_classes,id',
            'invoice_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'discount' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:draft,unpaid,partial,paid,cancelled',
            'items' => 'required|array|min:1',
        ];
    }
}
