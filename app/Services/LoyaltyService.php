<?php

namespace App\Services;

use App\Models\Client;
use App\Models\GiftCard;
use App\Models\LoyaltyPointTransaction;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyRewardRedemption;
use App\Models\StoreCoupon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Redemption of loyalty "items" (rewards) for points. The existing sale-based
 * earning stays untouched; this service only spends points and fulfils rewards,
 * writing a ledger row for every points movement it makes.
 */
class LoyaltyService
{
    public function __construct(private WalletService $wallets)
    {
    }

    public function balanceFor(int $clientId): float
    {
        return (float) (Client::where('id', $clientId)->value('points') ?? 0);
    }

    /**
     * Rewards a customer can currently see/redeem (active + in stock), ordered.
     */
    public function availableRewards()
    {
        return LoyaltyReward::where('active', true)
            ->where(fn ($q) => $q->whereNull('stock')->orWhere('stock', '>', 0))
            ->orderBy('sort_order')
            ->orderBy('points_cost')
            ->get();
    }

    /**
     * Redeem a reward for a customer: spend points, write the ledger, and fulfil
     * by type (gift_card → wallet credit, voucher → coupon, product → pending).
     */
    public function redeem(LoyaltyReward $reward, int $clientId, string $channel = 'storefront', ?int $userId = null): LoyaltyRewardRedemption
    {
        return DB::transaction(function () use ($reward, $clientId, $channel, $userId) {
            $client = Client::whereKey($clientId)->lockForUpdate()->first();
            if (! $client) {
                throw new LoyaltyException(__('messages.LoyaltyClientMissing'), 404);
            }
            if (! $client->is_royalty_eligible) {
                throw new LoyaltyException(__('messages.LoyaltyNotEligible'));
            }

            $r = LoyaltyReward::whereKey($reward->id)->lockForUpdate()->first();
            if (! $r || ! $r->isRedeemable()) {
                throw new LoyaltyException(__('messages.RewardUnavailable'), 422);
            }

            $cost = (float) $r->points_cost;
            if ((float) $client->points < $cost) {
                throw new LoyaltyException(__('messages.NotEnoughPoints'), 422, [
                    'balance' => (float) $client->points,
                    'required' => $cost,
                ]);
            }

            // Per-customer limit
            if ($r->per_customer_limit !== null) {
                $already = LoyaltyRewardRedemption::where('reward_id', $r->id)
                    ->where('client_id', $clientId)
                    ->where('status', '!=', 'cancelled')
                    ->count();
                if ($already >= $r->per_customer_limit) {
                    throw new LoyaltyException(__('messages.RewardLimitReached'), 422);
                }
            }

            // Spend points + ledger.
            $client->decrement('points', $cost);
            $newBalance = (float) $client->fresh()->points;
            LoyaltyPointTransaction::create([
                'client_id' => $clientId,
                'type' => 'redeem',
                'points' => -1 * $cost,
                'balance_after' => $newBalance,
                'source' => 'reward',
                'reference_type' => LoyaltyReward::class,
                'reference_id' => $r->id,
                'note' => __('messages.LoyaltyRedeemNote', ['name' => $r->name]),
                'created_by' => $userId,
            ]);

            if ($r->stock !== null) {
                $r->decrement('stock');
            }

            // Fulfil by type.
            $refType = null;
            $refId = null;
            $code = null;
            $status = 'issued';

            if ($r->type === 'gift_card') {
                $card = GiftCard::create([
                    'type' => 'gift_card',
                    'code' => $this->wallets->generateGiftCardCode('LGC'),
                    'name' => $r->name,
                    'amount' => $r->value,
                    'balance' => $r->value,
                    'status' => 'active',
                    'note' => __('messages.LoyaltyGiftCardNote'),
                    'created_by' => $userId,
                ]);
                // Credit the customer's wallet with the card value (marks card redeemed).
                $this->wallets->redeemGiftCard($card->code, $clientId, $userId);
                $refType = GiftCard::class;
                $refId = $card->id;
                $code = $card->code;
                $status = 'fulfilled';
            } elseif ($r->type === 'voucher') {
                $coupon = StoreCoupon::create([
                    'code' => $this->generateCouponCode(),
                    'description' => $r->name,
                    'type' => 'fixed',
                    'value' => $r->value,
                    'usage_limit' => 1,
                    'per_customer_limit' => 1,
                    'enabled' => true,
                ]);
                $refType = StoreCoupon::class;
                $refId = $coupon->id;
                $code = $coupon->code;
                $status = 'issued';
            } else { // product
                $refType = \App\Models\Product::class;
                $refId = $r->product_id;
                $status = 'issued'; // pending fulfilment by staff
            }

            return LoyaltyRewardRedemption::create([
                'reward_id' => $r->id,
                'client_id' => $clientId,
                'reward_name' => $r->name,
                'reward_type' => $r->type,
                'points_spent' => $cost,
                'status' => $status,
                'channel' => $channel,
                'reference_type' => $refType,
                'reference_id' => $refId,
                'code' => $code,
                'created_by' => $userId,
                'fulfilled_at' => $status === 'fulfilled' ? Carbon::now() : null,
                'fulfilled_by' => $status === 'fulfilled' ? $userId : null,
            ]);
        });
    }

    /**
     * Manual admin adjustment of a customer's points (credit or debit) with a ledger row.
     */
    public function adjust(int $clientId, string $type, float $points, ?string $note, ?int $userId): LoyaltyPointTransaction
    {
        $points = round(abs($points), 2);
        if ($points <= 0) {
            throw new LoyaltyException(__('messages.LoyaltyInvalidAmount'));
        }

        return DB::transaction(function () use ($clientId, $type, $points, $note, $userId) {
            $client = Client::whereKey($clientId)->lockForUpdate()->first();
            if (! $client) {
                throw new LoyaltyException(__('messages.LoyaltyClientMissing'), 404);
            }

            if ($type === 'debit') {
                if ((float) $client->points < $points) {
                    throw new LoyaltyException(__('messages.NotEnoughPoints'), 422);
                }
                $client->decrement('points', $points);
                $signed = -1 * $points;
            } else {
                $client->increment('points', $points);
                $signed = $points;
            }

            return LoyaltyPointTransaction::create([
                'client_id' => $clientId,
                'type' => 'adjustment',
                'points' => $signed,
                'balance_after' => (float) $client->fresh()->points,
                'source' => 'manual',
                'note' => $note,
                'created_by' => $userId,
            ]);
        });
    }

    /**
     * Cancel a still-issued redemption: refund the points and disable a voucher.
     * (Fulfilled gift-card redemptions already funded a wallet and cannot be auto-reversed.)
     */
    public function cancelRedemption(LoyaltyRewardRedemption $redemption, ?int $userId): LoyaltyRewardRedemption
    {
        if ($redemption->status !== 'issued') {
            throw new LoyaltyException(__('messages.RedemptionNotCancellable'), 422);
        }

        return DB::transaction(function () use ($redemption, $userId) {
            $client = Client::whereKey($redemption->client_id)->lockForUpdate()->first();
            if ($client) {
                $client->increment('points', (float) $redemption->points_spent);
                LoyaltyPointTransaction::create([
                    'client_id' => $client->id,
                    'type' => 'reversed',
                    'points' => (float) $redemption->points_spent,
                    'balance_after' => (float) $client->fresh()->points,
                    'source' => 'reward',
                    'reference_type' => LoyaltyRewardRedemption::class,
                    'reference_id' => $redemption->id,
                    'note' => __('messages.LoyaltyReversedNote', ['name' => $redemption->reward_name]),
                    'created_by' => $userId,
                ]);
            }

            // Disable an issued voucher so it can no longer be used.
            if ($redemption->reference_type === StoreCoupon::class && $redemption->reference_id) {
                StoreCoupon::whereKey($redemption->reference_id)->update(['enabled' => false]);
            }

            $redemption->update([
                'status' => 'cancelled',
                'fulfilled_by' => $userId,
                'fulfilled_at' => Carbon::now(),
            ]);

            return $redemption;
        });
    }

    private function generateCouponCode(): string
    {
        do {
            $code = 'LOY-'.strtoupper(Str::random(4).'-'.Str::random(4));
        } while (StoreCoupon::withTrashed()->where('code', $code)->exists());

        return $code;
    }
}
