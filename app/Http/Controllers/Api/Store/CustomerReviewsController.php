<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\OnlineOrder;
use App\Models\ProductReview;
use App\Models\StoreSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomerReviewsController extends Controller
{
    /** Order statuses that count as a completed purchase (eligible to review). */
    private const PURCHASED_STATUSES = ['confirmed', 'shipped', 'delivered'];

    private function ownOrderOrFail($id): OnlineOrder
    {
        $user = Auth::guard('store')->user();
        abort_unless($user, 401);
        abort_unless($user->client_id, 403);

        return OnlineOrder::with('items')
            ->where('id', $id)
            ->where('client_id', $user->client_id)
            ->firstOrFail();
    }

    /** GET /account/orders/{id}/reviewable — items in this order the customer can review. */
    public function reviewable($id)
    {
        $order = $this->ownOrderOrFail($id);

        $eligible = in_array($order->status, self::PURCHASED_STATUSES, true);

        $productIds = $order->items->pluck('product_id')->filter()->unique()->values();
        $existing = ProductReview::where('client_id', $order->client_id)
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        $order->loadMissing('items.product');
        $items = $order->items
            ->unique('product_id')
            ->map(function ($it) use ($existing) {
                $review = $existing->get($it->product_id);

                return [
                    'product_id' => $it->product_id,
                    'name' => optional($it->product)->name ?? ('#'.$it->product_id),
                    'my_review' => $review ? [
                        'rating' => $review->rating,
                        'comment' => $review->comment,
                        'status' => $review->status,
                    ] : null,
                ];
            })->values();

        return response()->json([
            'eligible' => $eligible,
            'order_status' => $order->status,
            'items' => $items,
        ]);
    }

    /** POST /account/orders/{id}/review — create or update the customer's review. */
    public function submit(Request $request, $id)
    {
        $order = $this->ownOrderOrFail($id);

        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        // Must be a completed purchase containing this product.
        if (! in_array($order->status, self::PURCHASED_STATUSES, true)) {
            return response()->json(['error' => __('messages.ReviewNotPurchased')], 422);
        }
        if (! $order->items->contains('product_id', (int) $data['product_id'])) {
            return response()->json(['error' => __('messages.ReviewProductNotInOrder')], 422);
        }

        $autoApprove = (bool) (StoreSetting::query()->value('auto_approve_reviews') ?? false);
        $clientName = optional(Client::find($order->client_id))->name;

        $review = ProductReview::updateOrCreate(
            ['client_id' => $order->client_id, 'product_id' => (int) $data['product_id']],
            [
                'online_order_id' => $order->id,
                'reviewer_name' => $clientName,
                'rating' => $data['rating'],
                'comment' => $data['comment'] ?? null,
                'status' => $autoApprove ? 'approved' : 'pending',
            ]
        );

        return response()->json([
            'success' => true,
            'status' => $review->status,
            'message' => $review->status === 'approved'
                ? __('messages.ReviewPublished')
                : __('messages.ReviewSubmittedForReview'),
        ], 201);
    }

    /** GET /products/{id}/reviews — public: approved reviews + average. */
    public function productReviews($id)
    {
        $reviews = ProductReview::approved()
            ->where('product_id', $id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['reviewer_name', 'rating', 'comment', 'created_at']);

        return response()->json([
            'count' => $reviews->count(),
            'average' => round((float) $reviews->avg('rating'), 1),
            'reviews' => $reviews->map(fn ($r) => [
                'name' => $r->reviewer_name ?: __('messages.Customer'),
                'rating' => (int) $r->rating,
                'comment' => $r->comment,
                'date' => optional($r->created_at)->toDateString(),
            ]),
        ]);
    }
}
