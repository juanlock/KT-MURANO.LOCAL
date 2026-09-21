<?php

namespace App\Http\Controllers;

use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\LabTest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Laboratory: the test catalogue, orders raised against it, and results.
 *
 * Prices and reference ranges are COPIED onto each order line at order time
 * rather than read through to the catalogue. A result printed last year must
 * keep the range it was judged against, and a bill must keep the price that
 * was charged — repricing history when the catalogue changes is a defect.
 */
class HospitalLabController extends Controller
{
    private const TEST_SORTABLE = ['id', 'name', 'code', 'category', 'price', 'is_active', 'created_at'];

    private const ORDER_SORTABLE = ['id', 'reference', 'ordered_at', 'status', 'priority', 'total', 'created_at'];

    // ------------------------------------------------------------------
    // Catalogue
    // ------------------------------------------------------------------

    public function tests(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', LabOrder::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = in_array($request->SortField, self::TEST_SORTABLE, true) ? $request->SortField : 'name';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = LabTest::whereNull('deleted_at');

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('active')) {
            $query->where('is_active', $request->active === '1' ? 1 : 0);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('code', 'LIKE', "%{$search}%")
                    ->orWhere('category', 'LIKE', "%{$search}%");
            });
        }

        $totalRows = $query->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $offset = ($page * $perPage) - $perPage;

        $rows = $query->orderBy($order, $dir)->offset($offset)->limit($perPage)->get();

        return response()->json([
            'totalRows' => $totalRows,
            'categories' => LabTest::whereNull('deleted_at')->whereNotNull('category')
                ->distinct()->orderBy('category')->pluck('category')->values(),
            'lab_tests' => $rows->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'code' => $t->code,
                'category' => $t->category,
                'sample_type' => $t->sample_type,
                'unit' => $t->unit,
                'normal_range' => $t->normal_range,
                'price' => (float) $t->price,
                'turnaround_hours' => $t->turnaround_hours,
                'is_active' => (bool) $t->is_active,
            ])->values(),
        ]);
    }

    public function storeTest(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', LabOrder::class);

        $request->validate($this->testRules());
        LabTest::create($this->testPayload($request));

        return response()->json(['success' => true]);
    }

    public function updateTest(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', LabOrder::class);

        $request->validate($this->testRules());
        LabTest::whereNull('deleted_at')->findOrFail($id)->update($this->testPayload($request));

        return response()->json(['success' => true]);
    }

    public function destroyTest(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', LabOrder::class);

        LabTest::whereNull('deleted_at')->findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------
    // Orders
    // ------------------------------------------------------------------

    public function orders(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', LabOrder::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = in_array($request->SortField, self::ORDER_SORTABLE, true) ? $request->SortField : 'ordered_at';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = LabOrder::with('patient', 'doctor', 'items')->whereNull('deleted_at');

        if ($request->filled('patient_id')) {
            $query->where('patient_id', $request->patient_id);
        }
        if ($request->filled('doctor_id')) {
            $query->where('doctor_id', $request->doctor_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }
        if ($request->boolean('pending')) {
            $query->whereIn('status', ['ordered', 'sample_collected', 'in_progress']);
        }
        if ($request->filled('start_date')) {
            $query->whereDate('ordered_at', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('ordered_at', '<=', $request->end_date);
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
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $offset = ($page * $perPage) - $perPage;

        $rows = $query->orderBy($order, $dir)->offset($offset)->limit($perPage)->get();

        return response()->json([
            'totalRows' => $totalRows,
            'lab_orders' => $rows->map(fn ($o) => $this->presentOrder($o))->values(),
        ]);
    }

    public function showOrder(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', LabOrder::class);

        $order = LabOrder::with('patient', 'doctor', 'items')->whereNull('deleted_at')->findOrFail($id);

        return response()->json(['lab_order' => $this->presentOrder($order, true)]);
    }

    public function storeOrder(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', LabOrder::class);

        $request->validate($this->orderRules());

        $userId = optional($request->user('api'))->id;

        $order = DB::transaction(function () use ($request, $userId) {
            $order = LabOrder::create([
                'reference' => LabOrder::nextReference('LAB'),
                'patient_id' => $request->patient_id,
                'doctor_id' => $request->doctor_id ?: null,
                'visit_id' => $request->visit_id ?: null,
                'ordered_at' => $request->ordered_at ?: now(),
                'priority' => $request->priority ?: 'routine',
                'status' => 'ordered',
                'notes' => $request->notes,
                'created_by' => $userId,
            ]);

            $this->syncItems($order, $request->input('test_ids'));

            return $order;
        });

        return response()->json(['success' => true, 'id' => $order->id, 'reference' => $order->reference]);
    }

    public function updateOrder(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', LabOrder::class);

        $order = LabOrder::whereNull('deleted_at')->findOrFail($id);
        $request->validate($this->orderRules());

        DB::transaction(function () use ($request, $order) {
            $order->update([
                'patient_id' => $request->patient_id,
                'doctor_id' => $request->doctor_id ?: null,
                'visit_id' => $request->visit_id ?: null,
                'ordered_at' => $request->ordered_at ?: $order->ordered_at,
                'priority' => $request->priority ?: $order->priority,
                'notes' => $request->notes,
            ]);

            // Results already entered must survive an edit of the test list.
            if ($request->filled('test_ids')) {
                $this->syncItems($order, $request->input('test_ids'), true);
            }
        });

        return response()->json(['success' => true]);
    }

    /**
     * Enter or amend results. Completing an order stamps completed_at; moving it
     * back out of completed clears that stamp so turnaround stats stay honest.
     */
    public function saveResults(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', LabOrder::class);

        $request->validate([
            'status' => 'required|in:ordered,sample_collected,in_progress,completed,cancelled',
            'results' => 'nullable|array',
            'results.*.id' => 'required|integer',
            'results.*.flag' => 'nullable|in:normal,low,high,critical',
        ]);

        $order = LabOrder::whereNull('deleted_at')->findOrFail($id);

        DB::transaction(function () use ($request, $order) {
            foreach ((array) $request->input('results', []) as $result) {
                LabOrderItem::where('lab_order_id', $order->id)
                    ->where('id', $result['id'])
                    ->update([
                        'result_value' => $result['result_value'] ?? null,
                        'flag' => $result['flag'] ?? null,
                        'remarks' => $result['remarks'] ?? null,
                    ]);
            }

            $order->update([
                'status' => $request->status,
                'completed_at' => $request->status === 'completed'
                    ? ($order->completed_at ?: now())
                    : null,
            ]);
        });

        return response()->json(['success' => true]);
    }

    public function destroyOrder(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', LabOrder::class);

        $order = LabOrder::whereNull('deleted_at')->findOrFail($id);
        DB::transaction(function () use ($order) {
            LabOrderItem::where('lab_order_id', $order->id)->delete();
            $order->delete();
        });

        return response()->json(['success' => true]);
    }

    public function deleteBySelection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', LabOrder::class);

        $ids = (array) $request->selectedIds;
        DB::transaction(function () use ($ids) {
            LabOrderItem::whereIn('lab_order_id', $ids)->delete();
            LabOrder::whereIn('id', $ids)->delete();
        });

        return response()->json(['success' => true, 'count' => count($ids)]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Bring the order's lines in line with the requested test ids. When
     * `$keepResults` is set, lines already on the order are left untouched so
     * an entered result is never wiped by adding one more test.
     */
    private function syncItems(LabOrder $order, $testIds, $keepResults = false)
    {
        $testIds = is_string($testIds) ? json_decode($testIds, true) : $testIds;
        $testIds = array_values(array_unique(array_filter((array) $testIds)));

        $existing = LabOrderItem::where('lab_order_id', $order->id)->get()->keyBy('lab_test_id');

        if ($keepResults) {
            LabOrderItem::where('lab_order_id', $order->id)
                ->whereNotIn('lab_test_id', $testIds ?: [0])
                ->delete();
        } else {
            LabOrderItem::where('lab_order_id', $order->id)->delete();
            $existing = collect();
        }

        $tests = LabTest::whereIn('id', $testIds)->get()->keyBy('id');
        $total = 0;

        foreach ($testIds as $testId) {
            $test = $tests->get($testId);
            if (! $test) {
                continue;
            }

            if ($keepResults && $existing->has($testId)) {
                $total += (float) $existing->get($testId)->price;
                continue;
            }

            LabOrderItem::create([
                'lab_order_id' => $order->id,
                'lab_test_id' => $test->id,
                // Snapshot: the catalogue may change, this order may not.
                'test_name' => $test->name,
                'price' => $test->price,
                'unit' => $test->unit,
                'normal_range' => $test->normal_range,
            ]);
            $total += (float) $test->price;
        }

        $order->update(['total' => round($total, 2)]);
    }

    private function presentOrder(LabOrder $o, $withItems = true)
    {
        $data = [
            'id' => $o->id,
            'reference' => $o->reference,
            'patient_id' => $o->patient_id,
            'patient_name' => $o->patient ? $o->patient->name : '',
            'patient_mrn' => $o->patient ? $o->patient->mrn : '',
            'doctor_id' => $o->doctor_id,
            'doctor_name' => $o->doctor ? $o->doctor->name : null,
            'visit_id' => $o->visit_id,
            'ordered_at' => optional($o->ordered_at)->toIso8601String(),
            'completed_at' => optional($o->completed_at)->toIso8601String(),
            'priority' => $o->priority,
            'status' => $o->status,
            'total' => (float) $o->total,
            'notes' => $o->notes,
            'test_count' => $o->items->count(),
            'abnormal_count' => $o->items->whereIn('flag', ['low', 'high', 'critical'])->count(),
        ];

        if ($withItems) {
            $data['items'] = $o->items->map(fn ($i) => [
                'id' => $i->id,
                'lab_test_id' => $i->lab_test_id,
                'test_name' => $i->test_name,
                'price' => (float) $i->price,
                'result_value' => $i->result_value,
                'unit' => $i->unit,
                'normal_range' => $i->normal_range,
                'flag' => $i->flag,
                'remarks' => $i->remarks,
            ])->values();
        }

        return $data;
    }

    private function testRules()
    {
        return [
            'name' => 'required|string|max:191',
            'code' => 'nullable|string|max:32',
            'price' => 'nullable|numeric|min:0',
            'turnaround_hours' => 'nullable|integer|min:0|max:2000',
        ];
    }

    private function testPayload(Request $request)
    {
        return [
            'name' => $request->name,
            'code' => $request->code,
            'category' => $request->category,
            'sample_type' => $request->sample_type,
            'unit' => $request->unit,
            'normal_range' => $request->normal_range,
            'price' => $request->price ?: 0,
            'turnaround_hours' => $request->turnaround_hours ?: null,
            'is_active' => $request->boolean('is_active', true),
        ];
    }

    private function orderRules()
    {
        return [
            'patient_id' => 'required|exists:patients,id',
            'doctor_id' => 'nullable|exists:doctors,id',
            'visit_id' => 'nullable|exists:patient_visits,id',
            'ordered_at' => 'nullable|date',
            'priority' => 'nullable|in:routine,urgent,stat',
            'test_ids' => 'required|array|min:1',
        ];
    }
}
