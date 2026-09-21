<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\LoyaltyPointTransaction;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyRewardRedemption;
use App\Models\Sale;
use App\Services\LoyaltyException;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class LoyaltyRewardController extends BaseController
{
    public function __construct(private LoyaltyService $loyalty)
    {
    }

    // ---------------------------- Rewards CRUD ----------------------------

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', LoyaltyReward::class);

        $query = LoyaltyReward::withCount('redemptions')
            ->orderBy('sort_order')->orderByDesc('id');

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->search.'%');
        }
        if ($request->filled('type') && in_array($request->type, LoyaltyReward::TYPES, true)) {
            $query->where('type', $request->type);
        }

        return response()->json(['rewards' => $query->get()->map(fn (LoyaltyReward $r) => $this->present($r))]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', LoyaltyReward::class);
        $data = $this->validateData($request);
        $reward = LoyaltyReward::create($data);

        return response()->json(['success' => true, 'id' => $reward->id], 201);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', LoyaltyReward::class);
        $reward = LoyaltyReward::findOrFail($id);
        $reward->update($this->validateData($request));

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', LoyaltyReward::class);
        LoyaltyReward::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    private function validateData(Request $request): array
    {
        if ($request->has('active')) {
            $request->merge(['active' => (int) filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN)]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'type' => ['required', Rule::in(LoyaltyReward::TYPES)],
            'points_cost' => ['required', 'numeric', 'min:0.01'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'product_id' => ['nullable', 'integer', 'required_if:type,product', 'exists:products,id'],
            'image' => ['nullable', 'string', 'max:191'],
            'active' => ['nullable', 'in:0,1'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        // gift_card / voucher must carry a value.
        if (in_array($data['type'], ['gift_card', 'voucher'], true) && (float) ($data['value'] ?? 0) <= 0) {
            abort(response()->json(['errors' => ['value' => [__('messages.RewardValueRequired')]]], 422));
        }

        return $data;
    }

    // ---------------------------- Redemptions ----------------------------

    public function redemptions(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', LoyaltyReward::class);

        $per = (int) $request->query('per_page', 15);
        $query = LoyaltyRewardRedemption::with('client:id,name,email')->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn ($q) => $q->where('reward_name', 'like', "%{$s}%")
                ->orWhere('code', 'like', "%{$s}%")
                ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$s}%")));
        }

        $rows = $query->paginate($per);

        return response()->json([
            'data' => $rows->getCollection()->map(fn (LoyaltyRewardRedemption $r) => [
                'id' => $r->id,
                'client_name' => optional($r->client)->name,
                'reward_name' => $r->reward_name,
                'reward_type' => $r->reward_type,
                'points_spent' => (float) $r->points_spent,
                'status' => $r->status,
                'channel' => $r->channel,
                'code' => $r->code,
                'created_at' => optional($r->created_at)->toDateTimeString(),
            ]),
            'meta' => ['total' => $rows->total(), 'page' => $rows->currentPage(), 'pages' => $rows->lastPage()],
        ]);
    }

    public function fulfill(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', LoyaltyReward::class);
        $redemption = LoyaltyRewardRedemption::findOrFail($id);
        if ($redemption->status !== 'issued') {
            return response()->json(['error' => __('messages.RedemptionNotPending')], 422);
        }
        $redemption->update([
            'status' => 'fulfilled',
            'fulfilled_by' => Auth::id(),
            'fulfilled_at' => Carbon::now(),
        ]);

        return response()->json(['success' => true]);
    }

    public function cancel(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', LoyaltyReward::class);
        $redemption = LoyaltyRewardRedemption::findOrFail($id);
        try {
            $this->loyalty->cancelRedemption($redemption, Auth::id());
        } catch (LoyaltyException $e) {
            return $e->toResponse();
        }

        return response()->json(['success' => true]);
    }

    // ---------------------------- Manual adjustment ----------------------------

    public function adjust(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', LoyaltyReward::class);
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'type' => ['required', 'in:credit,debit'],
            'points' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->loyalty->adjust((int) $data['client_id'], $data['type'], (float) $data['points'], $data['note'] ?? null, Auth::id());
        } catch (LoyaltyException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'success' => true,
            'balance' => $this->loyalty->balanceFor((int) $data['client_id']),
        ]);
    }

    // ---------------------------- POS redemption ----------------------------

    /** Available rewards + a customer's point balance, for the POS reward picker. */
    public function posRewards(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $clientId = (int) $request->query('client_id', 0);
        $balance = $clientId ? $this->loyalty->balanceFor($clientId) : 0.0;
        $eligible = $clientId ? (bool) Client::where('id', $clientId)->value('is_royalty_eligible') : false;

        return response()->json([
            'points' => $balance,
            'eligible' => $eligible,
            'rewards' => $this->loyalty->availableRewards()->map(fn (LoyaltyReward $r) => $this->present($r)),
        ]);
    }

    /** Redeem a reward for a customer at the POS. */
    public function posRedeem(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'Sales_pos', Sale::class);

        $data = $request->validate([
            'reward_id' => ['required', 'integer'],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
        ]);

        $reward = LoyaltyReward::find($data['reward_id']);
        if (! $reward) {
            return response()->json(['error' => __('messages.RewardUnavailable')], 404);
        }

        try {
            $redemption = $this->loyalty->redeem($reward, (int) $data['client_id'], 'pos', Auth::id());
        } catch (LoyaltyException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'success' => true,
            'reward_type' => $redemption->reward_type,
            'code' => $redemption->code,
            'points' => $this->loyalty->balanceFor((int) $data['client_id']),
        ]);
    }

    /** Lightweight client lookup for the adjust modal (by name/email). */
    public function searchClients(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', LoyaltyReward::class);
        $s = trim((string) $request->query('search', ''));

        $clients = Client::when($s !== '', fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"))
            ->orderBy('name')->limit(20)->get(['id', 'name', 'email', 'points']);

        return response()->json(['clients' => $clients]);
    }

    private function present(LoyaltyReward $r): array
    {
        return [
            'id' => $r->id,
            'name' => $r->name,
            'description' => $r->description,
            'type' => $r->type,
            'points_cost' => (float) $r->points_cost,
            'value' => (float) $r->value,
            'product_id' => $r->product_id,
            'image' => $r->image,
            'active' => (bool) $r->active,
            'stock' => $r->stock,
            'per_customer_limit' => $r->per_customer_limit,
            'sort_order' => $r->sort_order,
            'redemptions_count' => (int) $r->redemptions_count,
        ];
    }
}
