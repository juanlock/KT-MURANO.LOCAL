<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\LoyaltyPointTransaction;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyRewardRedemption;
use App\Models\StoreSetting;
use App\Services\LoyaltyException;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomerLoyaltyController extends Controller
{
    public function __construct(private LoyaltyService $loyalty)
    {
    }

    /**
     * Points balance + redeemable rewards + recent activity for the logged-in
     * storefront customer.
     */
    public function summary(Request $request)
    {
        [$user, $error] = $this->requireCustomer();
        if ($error) {
            return $error;
        }

        $client = Client::find($user->client_id);
        $eligible = $client && $client->is_royalty_eligible;

        $rewards = $this->loyalty->availableRewards()->map(fn (LoyaltyReward $r) => $this->presentReward($r));

        $recent = LoyaltyPointTransaction::where('client_id', $user->client_id)
            ->orderByDesc('id')->limit(10)->get()
            ->map(fn (LoyaltyPointTransaction $t) => [
                'id' => $t->id,
                'type' => $t->type,
                'points' => (float) $t->points,
                'balance_after' => (float) $t->balance_after,
                'source' => $t->source,
                'note' => $t->note,
                'created_at' => optional($t->created_at)->toDateTimeString(),
            ]);

        return response()->json([
            'points' => (float) ($client->points ?? 0),
            'eligible' => (bool) $eligible,
            'currency' => (string) (StoreSetting::query()->value('currency_code') ?: ''),
            'rewards' => $rewards,
            'transactions' => $recent,
        ]);
    }

    public function redeem(Request $request)
    {
        [$user, $error] = $this->requireCustomer();
        if ($error) {
            return $error;
        }

        $data = $request->validate(['reward_id' => ['required', 'integer']]);
        $reward = LoyaltyReward::find($data['reward_id']);
        if (! $reward) {
            return response()->json(['error' => __('messages.RewardUnavailable')], 404);
        }

        try {
            $redemption = $this->loyalty->redeem($reward, (int) $user->client_id, 'storefront', null);
        } catch (LoyaltyException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'success' => true,
            'reward_type' => $redemption->reward_type,
            'code' => $redemption->code,
            'points' => $this->loyalty->balanceFor((int) $user->client_id),
        ]);
    }

    public function redemptions(Request $request)
    {
        [$user, $error] = $this->requireCustomer();
        if ($error) {
            return $error;
        }

        $rows = LoyaltyRewardRedemption::where('client_id', $user->client_id)
            ->orderByDesc('id')->limit(50)->get()
            ->map(fn (LoyaltyRewardRedemption $r) => [
                'id' => $r->id,
                'reward_name' => $r->reward_name,
                'reward_type' => $r->reward_type,
                'points_spent' => (float) $r->points_spent,
                'status' => $r->status,
                'code' => $r->code,
                'created_at' => optional($r->created_at)->toDateTimeString(),
            ]);

        return response()->json(['data' => $rows]);
    }

    private function requireCustomer(): array
    {
        $user = Auth::guard('store')->user();
        if (! $user) {
            return [null, response()->json(['error' => 'Unauthenticated'], 401)];
        }
        if (! $user->client_id) {
            return [null, response()->json(['error' => __('messages.CustomerProfileMissing')], 422)];
        }

        return [$user, null];
    }

    private function presentReward(LoyaltyReward $r): array
    {
        return [
            'id' => $r->id,
            'name' => $r->name,
            'description' => $r->description,
            'type' => $r->type,
            'points_cost' => (float) $r->points_cost,
            'value' => (float) $r->value,
            'image' => $r->image,
        ];
    }
}
