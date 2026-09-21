<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\ProductQuestion;
use App\Models\StoreSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin side of "Ask About This Item". Gated on the store-settings permission,
 * like every other online-store admin surface.
 */
class ProductQuestionsController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $query = ProductQuestion::with(['product:id,name,code', 'answerer:id,firstname,lastname,username'])
            ->orderByDesc('created_at');

        if ($request->filled('product_id')) {
            $query->where('product_id', (int) $request->product_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->boolean('unanswered')) {
            $query->whereNull('answered_at');
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('question', 'like', "%{$s}%")
                    ->orWhere('answer', 'like', "%{$s}%")
                    ->orWhere('asker_name', 'like', "%{$s}%")
                    ->orWhereHas('product', fn ($qq) => $qq->where('name', 'like', "%{$s}%"));
            });
        }

        $rows = $query->paginate((int) $request->input('per_page', 15));

        return response()->json([
            'data' => $rows->getCollection()->map(fn (ProductQuestion $q) => $this->present($q)),
            'meta' => [
                'total' => $rows->total(),
                'pending_count' => ProductQuestion::whereNull('answered_at')->count(),
            ],
        ]);
    }

    /**
     * Answer a question. Answering publishes it, since an answered question is
     * exactly what the product page is meant to show.
     */
    public function answer(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', StoreSetting::class);

        $data = $request->validate([
            'answer' => ['required', 'string', 'max:2000'],
            'publish' => ['nullable', 'boolean'],
        ]);

        $question = ProductQuestion::findOrFail($id);
        $question->answer = trim($data['answer']);
        $question->answered_by = optional($request->user('api'))->id;
        $question->answered_at = now();
        if ($data['publish'] ?? true) {
            $question->status = 'published';
        }
        $question->save();

        return response()->json(['success' => true, 'question' => $this->present($question->fresh())]);
    }

    public function setStatus(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', StoreSetting::class);

        $data = $request->validate([
            'status' => ['required', Rule::in(ProductQuestion::STATUSES)],
        ]);

        $question = ProductQuestion::findOrFail($id);
        $question->status = $data['status'];
        $question->save();

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', StoreSetting::class);

        ProductQuestion::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    private function present(ProductQuestion $q): array
    {
        $answerer = $q->answerer;

        return [
            'id' => $q->id,
            'product_id' => $q->product_id,
            'product_name' => optional($q->product)->name,
            'asker_name' => $q->asker_name,
            'question' => $q->question,
            'answer' => $q->answer,
            'answered_by' => $answerer
                ? trim(($answerer->firstname ?? '').' '.($answerer->lastname ?? '')) ?: $answerer->username
                : null,
            'answered_at' => optional($q->answered_at)->toDateTimeString(),
            'status' => $q->status,
            'created_at' => optional($q->created_at)->toDateTimeString(),
        ];
    }
}
