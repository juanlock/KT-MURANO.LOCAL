<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\OnlineOrderReturn;
use App\Models\StoreSetting;
use App\Services\CheckoutException;
use App\Services\OnlineReturnService;
use Illuminate\Http\Request;

class ReturnsController extends Controller
{
    public function __construct(private OnlineReturnService $returns)
    {
    }

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $query = OnlineOrderReturn::with(['order:id,ref,total,payment_method,payment_status', 'items'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->whereHas('order', fn ($q) => $q->where('ref', 'like', "%{$s}%"));
        }

        $rows = $query->paginate((int) $request->input('per_page', 15));

        $data = $rows->getCollection()->map(fn (OnlineOrderReturn $r) => [
            'id' => $r->id,
            'order_id' => $r->order_id,
            'order_ref' => optional($r->order)->ref,
            'type' => $r->type,
            'status' => $r->status,
            'reason' => $r->reason,
            'admin_note' => $r->admin_note,
            'refund_amount' => (float) $r->refund_amount,
            'refunded_at' => optional($r->refunded_at)->toDateTimeString(),
            'created_at' => optional($r->created_at)->toDateTimeString(),
            'items' => $r->items->map(fn ($i) => [
                'product_id' => $i->product_id,
                'qty' => (float) $i->qty,
                'amount' => (float) $i->amount,
            ]),
            'payment_method' => optional($r->order)->payment_method,
        ]);

        return response()->json([
            'data' => $data,
            'meta' => ['total' => $rows->total(), 'page' => $rows->currentPage(), 'pages' => $rows->lastPage()],
        ]);
    }

    public function approve(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $data = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:1000'],
            'refund_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $return = OnlineOrderReturn::with('order', 'items')->findOrFail($id);

        try {
            $return = $this->returns->approve(
                $return,
                optional($request->user('api'))->id,
                $data['admin_note'] ?? null,
                $data['refund_amount'] ?? null
            );
        } catch (CheckoutException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'success' => true,
            'status' => $return->status,
            'refund_amount' => (float) $return->refund_amount,
            'refund_reference' => $return->refund_reference,
        ]);
    }

    public function reject(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $data = $request->validate(['admin_note' => ['nullable', 'string', 'max:1000']]);

        $return = OnlineOrderReturn::findOrFail($id);

        try {
            $return = $this->returns->reject($return, optional($request->user('api'))->id, $data['admin_note'] ?? null);
        } catch (CheckoutException $e) {
            return $e->toResponse();
        }

        return response()->json(['success' => true, 'status' => $return->status]);
    }
}
