<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\OnlineOrder;
use App\Services\CheckoutException;
use App\Services\OnlineReturnService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomerReturnsController extends Controller
{
    public function __construct(private OnlineReturnService $returns)
    {
    }

    /** The customer's own order, or 404. */
    private function ownOrderOrFail($id): OnlineOrder
    {
        $user = Auth::guard('store')->user();
        abort_unless($user, 401);
        abort_unless($user->client_id, 403);

        return OnlineOrder::where('id', $id)
            ->where('client_id', $user->client_id)
            ->firstOrFail();
    }

    /** GET /my/returns — all of the customer's return & cancellation requests. */
    public function myReturns(Request $request)
    {
        $user = Auth::guard('store')->user();
        abort_unless($user, 401);
        abort_unless($user->client_id, 403);

        $returns = \App\Models\OnlineOrderReturn::query()
            ->with(['order:id,ref,status', 'items'])
            ->whereHas('order', fn ($q) => $q->where('client_id', (int) $user->client_id))
            ->orderByDesc('created_at')
            ->get();

        $rows = $returns->map(function (\App\Models\OnlineOrderReturn $r) {
            return [
                'id' => $r->id,
                'order_id' => $r->order_id,
                'order_ref' => optional($r->order)->ref ?: ('#'.$r->order_id),
                'type' => $r->type,          // cancellation | return
                'status' => $r->status,      // requested | approved | rejected | refunded
                'reason' => $r->reason,
                'refund_amount' => (float) $r->refund_amount,
                'items_count' => $r->items->count(),
                'created_at' => optional($r->created_at)->toDateTimeString(),
                'refunded_at' => optional($r->refunded_at)->toDateTimeString(),
            ];
        });

        return response()->json(['data' => $rows]);
    }

    /** GET /account/orders/{id}/return-eligibility */
    public function eligibility($id)
    {
        $order = $this->ownOrderOrFail($id);

        return response()->json([
            'can_cancel' => $this->returns->cancelEligibility($order),
            'can_return' => $this->returns->returnEligibility($order),
            'returnable_items' => $this->returns->returnableItems($order),
            'open_requests' => $order->returns()
                ->whereIn('status', ['requested', 'approved'])
                ->get(['id', 'type', 'status', 'refund_amount', 'created_at']),
        ]);
    }

    /** POST /account/orders/{id}/cancel */
    public function cancel(Request $request, $id)
    {
        $order = $this->ownOrderOrFail($id);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        try {
            $return = $this->returns->requestCancellation($order, $data['reason'] ?? null);
        } catch (CheckoutException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'success' => true,
            'status' => $return->status,
            'order_status' => $order->fresh()->status,
            'message' => $return->status === 'refunded'
                ? __('messages.OrderCancelled')
                : __('messages.CancellationRequested'),
        ], 201);
    }

    /** POST /account/orders/{id}/return */
    public function requestReturn(Request $request, $id)
    {
        $order = $this->ownOrderOrFail($id);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.online_order_item_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
        ]);

        try {
            $return = $this->returns->requestReturn($order, $data['items'], $data['reason'] ?? null);
        } catch (CheckoutException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'success' => true,
            'return_id' => $return->id,
            'status' => $return->status,
            'refund_amount' => (float) $return->refund_amount,
            'message' => __('messages.ReturnRequested'),
        ], 201);
    }
}
