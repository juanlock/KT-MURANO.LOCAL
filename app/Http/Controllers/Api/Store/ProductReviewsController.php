<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\ProductReview;
use App\Models\StoreSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductReviewsController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $query = ProductReview::with('product:id,name,code')->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('rating')) {
            $query->where('rating', (int) $request->rating);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('reviewer_name', 'like', "%{$s}%")
                  ->orWhere('comment', 'like', "%{$s}%")
                  ->orWhereHas('product', fn ($qq) => $qq->where('name', 'like', "%{$s}%"));
            });
        }

        $rows = $query->paginate((int) $request->input('per_page', 15));

        $data = $rows->getCollection()->map(fn (ProductReview $r) => [
            'id' => $r->id,
            'product_id' => $r->product_id,
            'product_name' => optional($r->product)->name,
            'reviewer_name' => $r->reviewer_name,
            'rating' => (int) $r->rating,
            'comment' => $r->comment,
            'status' => $r->status,
            'created_at' => optional($r->created_at)->toDateTimeString(),
        ]);

        return response()->json([
            'data' => $data,
            'meta' => ['total' => $rows->total(), 'page' => $rows->currentPage(), 'pages' => $rows->lastPage()],
            'counts' => [
                'pending' => ProductReview::where('status', 'pending')->count(),
                'approved' => ProductReview::where('status', 'approved')->count(),
                'rejected' => ProductReview::where('status', 'rejected')->count(),
            ],
        ]);
    }

    public function setStatus(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $data = $request->validate([
            'status' => ['required', Rule::in(ProductReview::STATUSES)],
        ]);

        $review = ProductReview::findOrFail($id);
        $review->status = $data['status'];
        $review->save();

        return response()->json(['success' => true, 'status' => $review->status]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        ProductReview::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }
}
