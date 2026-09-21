<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\StoreSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Storefront side of "Ask About This Item": asking (signed-in customers only),
 * the public Q&A list on the product page, and the customer's own history.
 */
class CustomerQuestionsController extends Controller
{
    /** GET /products/{id}/questions — published Q&A, readable by anyone. */
    public function productQuestions($id)
    {
        $rows = ProductQuestion::published()
            ->where('product_id', (int) $id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $me = Auth::guard('store')->user();

        return response()->json([
            'count' => $rows->count(),
            'can_ask' => (bool) $me,
            'questions' => $rows->map(fn (ProductQuestion $q) => [
                'id' => $q->id,
                'asker_name' => $this->displayName($q->asker_name),
                'question' => $q->question,
                'answer' => $q->answer,
                'asked_at' => store_date($q->created_at),
                'answered_at' => $q->answered_at ? store_date($q->answered_at) : null,
                'is_mine' => $me && (int) $me->client_id === (int) $q->client_id,
            ])->values(),
        ]);
    }

    /** POST /products/{id}/questions — ask. Registered customers only. */
    public function ask(Request $request, $id)
    {
        $user = Auth::guard('store')->user();
        if (! $user) {
            return response()->json(['message' => __('messages.MustBeSignedInToAsk')], 401);
        }
        if (! $user->client_id) {
            return response()->json(['message' => __('messages.CustomerProfileMissing')], 403);
        }

        $product = Product::whereNull('deleted_at')
            ->where('is_active', 1)
            ->where('hide_from_online_store', 0)
            ->find((int) $id);
        if (! $product) {
            return response()->json(['message' => __('messages.NotFound')], 404);
        }

        $data = $request->validate([
            'question' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        // A customer with several unanswered questions on the same product is
        // almost always a mis-click or spam.
        $openCount = ProductQuestion::where('product_id', $product->id)
            ->where('client_id', $user->client_id)
            ->whereNull('answered_at')
            ->count();
        if ($openCount >= 5) {
            return response()->json(['message' => __('messages.TooManyOpenQuestions')], 429);
        }

        ProductQuestion::create([
            'product_id' => $product->id,
            'client_id' => $user->client_id,
            'asker_name' => $user->username ?: $user->email,
            'question' => trim($data['question']),
            'status' => 'pending',
        ]);

        return response()->json(['success' => true, 'message' => __('messages.QuestionSubmitted')], 201);
    }

    /** GET /account/questions — the signed-in customer's own questions. */
    public function mine()
    {
        $s = StoreSetting::firstOrFail();
        $user = Auth::guard('store')->user();

        $questions = $user && $user->client_id
            ? ProductQuestion::with('product:id,name')
                ->where('client_id', $user->client_id)
                ->orderByDesc('created_at')
                ->get()
            : collect();

        return view('store.account-questions', compact('s', 'questions'));
    }

    /**
     * Only ever show a first name + initial publicly — the full account name is
     * not the customer's to leak on a public product page.
     */
    private function displayName(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return __('messages.Customer');
        }

        $parts = preg_split('/\s+/', $name);
        $first = $parts[0];
        if (str_contains($first, '@')) {
            $first = Str::before($first, '@');
        }

        return count($parts) > 1
            ? $first.' '.Str::upper(Str::substr($parts[count($parts) - 1], 0, 1)).'.'
            : $first;
    }
}
