<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\GiftCard;
use App\Models\StoreSetting;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class GiftCardController extends Controller
{
    public function __construct(private WalletService $wallets)
    {
    }


    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', GiftCard::class);

        $per = (int) $request->query('per_page', 10);

        $query = GiftCard::with('redeemer:id,name')->orderByDesc('id');

        if ($request->filled('search')) {
            $term = $request->query('search');
            $query->where(fn ($q) => $q->where('code', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%"));
        }
        if ($request->filled('status') && in_array($request->query('status'), GiftCard::STATUSES, true)) {
            $query->where('status', $request->query('status'));
        }

        $cards = $query->paginate($per);

        return response()->json([
            'data' => $cards->getCollection()->map(fn (GiftCard $c) => $this->present($c)),
            'meta' => [
                'total' => $cards->total(),
                'page' => $cards->currentPage(),
                'pages' => $cards->lastPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', GiftCard::class);

        // Batch generation: create N cards of the same value at once.
        $quantity = (int) $request->input('quantity', 1);
        $quantity = max(1, min($quantity, 500));

        $data = $this->validateData($request, null, $quantity > 1);

        $created = [];
        for ($i = 0; $i < $quantity; $i++) {
            $code = $quantity > 1 || empty($data['code'])
                ? $this->wallets->generateGiftCardCode()
                : $data['code'];

            $created[] = GiftCard::create([
                'type' => $data['type'],
                'code' => $code,
                'name' => $data['name'] ?? null,
                'amount' => $data['amount'],
                'balance' => $data['amount'],
                'status' => $data['status'] ?? 'active',
                'expires_at' => $data['expires_at'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => Auth::id(),
            ]);
        }

        return response()->json([
            'success' => true,
            'count' => count($created),
            'cards' => collect($created)->map(fn (GiftCard $c) => $this->present($c)),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', GiftCard::class);

        $card = GiftCard::findOrFail($id);

        // A redeemed card is immutable except for admin metadata.
        if ($card->status === 'redeemed') {
            $data = $request->validate([
                'name' => ['nullable', 'string', 'max:120'],
                'note' => ['nullable', 'string', 'max:255'],
            ]);
            $card->fill($data)->save();

            return response()->json(['success' => true]);
        }

        $data = $this->validateData($request, $card->id, false);

        // Keep remaining balance in step with the face value while unredeemed.
        $card->fill([
            'type' => $data['type'],
            'code' => $data['code'],
            'name' => $data['name'] ?? null,
            'amount' => $data['amount'],
            'balance' => $data['amount'],
            'status' => $data['status'] ?? $card->status,
            'expires_at' => $data['expires_at'] ?? null,
            'note' => $data['note'] ?? null,
        ])->save();

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', GiftCard::class);

        GiftCard::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    private function validateData(Request $request, ?int $id, bool $batch): array
    {
        $rules = [
            'type' => ['required', Rule::in(GiftCard::TYPES)],
            'name' => ['nullable', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'status' => ['nullable', Rule::in(['active', 'disabled'])],
            'expires_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ];

        // A code is only user-supplied for single-card creation/edits.
        if (! $batch) {
            // The DB unique index on `code` spans soft-deleted rows, so validate
            // against ALL rows (including trashed) to avoid a 500 on insert.
            $rules['code'] = ['nullable', 'string', 'max:60', Rule::unique('gift_cards', 'code')->ignore($id)];
        }

        $data = $request->validate($rules);

        if (! $batch && ! empty($data['code'])) {
            $data['code'] = strtoupper(trim($data['code']));
        }

        return $data;
    }

    private function present(GiftCard $c): array
    {
        return [
            'id' => $c->id,
            'type' => $c->type,
            'code' => $c->code,
            'name' => $c->name,
            'amount' => (float) $c->amount,
            'balance' => (float) $c->balance,
            'status' => $c->status,
            'expires_at' => optional($c->expires_at)->format('Y-m-d\TH:i'),
            'note' => $c->note,
            'redeemed_by' => $c->redeemed_by,
            'redeemer_name' => optional($c->redeemer)->name,
            'redeemed_at' => optional($c->redeemed_at)->toDateTimeString(),
            'created_at' => optional($c->created_at)->toDateTimeString(),
        ];
    }
}
