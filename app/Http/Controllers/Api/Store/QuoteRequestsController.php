<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\BaseController;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StoreQuoteRequest;
use App\Models\StoreSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class QuoteRequestsController extends BaseController
{
    /** POST /online_store/quote-request — public: a visitor requests a quotation. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $productName = null;
        if (! empty($data['product_id'])) {
            $productName = Product::where('id', $data['product_id'])->value('name');
        }

        $user = Auth::guard('store')->user();

        $quote = StoreQuoteRequest::create([
            'product_id' => $data['product_id'] ?? null,
            'product_name' => $productName,
            'client_id' => $user->client_id ?? null,
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'quantity' => $data['quantity'] ?? null,
            'message' => $data['message'] ?? null,
            'status' => 'new',
        ]);

        $this->notifyOwner($quote);

        return response()->json([
            'success' => true,
            'message' => __('messages.QuoteRequestSent'),
        ], 201);
    }

    /** GET /store/quote-requests — admin list. */
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $query = StoreQuoteRequest::with('product:id,name,code')->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%")
                  ->orWhere('product_name', 'like', "%{$s}%");
            });
        }

        $rows = $query->paginate((int) $request->input('per_page', 15));

        $data = $rows->getCollection()->map(fn (StoreQuoteRequest $q) => [
            'id' => $q->id,
            'product_id' => $q->product_id,
            'product_name' => $q->product_name ?: optional($q->product)->name,
            'name' => $q->name,
            'email' => $q->email,
            'phone' => $q->phone,
            'quantity' => $q->quantity !== null ? (float) $q->quantity : null,
            'message' => $q->message,
            'status' => $q->status,
            'created_at' => optional($q->created_at)->toDateTimeString(),
        ]);

        return response()->json([
            'data' => $data,
            'meta' => ['total' => $rows->total(), 'page' => $rows->currentPage(), 'pages' => $rows->lastPage()],
            'counts' => [
                'new' => StoreQuoteRequest::where('status', 'new')->count(),
                'handled' => StoreQuoteRequest::where('status', 'handled')->count(),
                'closed' => StoreQuoteRequest::where('status', 'closed')->count(),
            ],
        ]);
    }

    public function setStatus(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $data = $request->validate(['status' => ['required', Rule::in(StoreQuoteRequest::STATUSES)]]);

        $quote = StoreQuoteRequest::findOrFail($id);
        $quote->status = $data['status'];
        $quote->save();

        return response()->json(['success' => true, 'status' => $quote->status]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        StoreQuoteRequest::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    /** Best-effort email to the store owner about a new quote request. */
    private function notifyOwner(StoreQuoteRequest $quote): void
    {
        try {
            $this->Set_config_mail();
            $ownerEmail = StoreSetting::query()->value('contact_email')
                ?: optional(Setting::whereNull('deleted_at')->first())->email;

            if ($ownerEmail) {
                $lines = [
                    'Product: '.($quote->product_name ?: '—'),
                    'Name: '.$quote->name,
                    'Email: '.$quote->email,
                    'Phone: '.($quote->phone ?: '—'),
                    'Quantity: '.($quote->quantity !== null ? $quote->quantity : '—'),
                    'Message: '.($quote->message ?: '—'),
                ];
                $body = implode("\n", $lines);
                Mail::raw($body, function ($m) use ($ownerEmail, $quote) {
                    $m->to($ownerEmail)->subject('New Quotation Request'.($quote->product_name ? ' — '.$quote->product_name : ''));
                });
            }
        } catch (\Throwable $e) {
            Log::warning('Quote request owner email failed: '.$e->getMessage());
        }
    }
}
