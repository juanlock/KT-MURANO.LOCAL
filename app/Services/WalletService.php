<?php

namespace App\Services;

use App\Models\GiftCard;
use App\Models\PaymentMethod;
use App\Models\StoreSetting;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Server-authoritative wallet ledger. All balance mutations MUST go through
 * credit()/debit() so that every change writes a matching wallet_transactions
 * row and the running balance stays consistent under concurrency (row locks).
 */
class WalletService
{
    protected ?int $walletMethodId = null;

    protected bool $walletMethodIdLoaded = false;

    /**
     * Id of the "Wallet" tender in payment_methods, resolved by name because
     * an install may already use any given id (e.g. Wave at id 8) for another
     * method. Returns null when no Wallet method exists.
     */
    public function paymentMethodId(): ?int
    {
        if (! $this->walletMethodIdLoaded) {
            $this->walletMethodIdLoaded = true;
            $id = PaymentMethod::query()
                ->whereNull('deleted_at')
                ->whereRaw('LOWER(TRIM(name)) = ?', ['wallet'])
                ->value('id');
            $this->walletMethodId = $id !== null ? (int) $id : null;
        }

        return $this->walletMethodId;
    }

    /**
     * Get (or lazily create) the wallet for a POS client id. Storefront accounts
     * map to a client via ecommerce_clients.client_id, so the wallet is shared.
     */
    public function walletFor(int $clientId): Wallet
    {
        return Wallet::firstOrCreate(
            ['client_id' => $clientId],
            [
                'balance' => 0,
                'status' => 'active',
                'currency' => (string) (StoreSetting::query()->value('currency_code') ?: null),
            ]
        );
    }

    public function balanceFor(int $clientId): float
    {
        return (float) (Wallet::where('client_id', $clientId)->value('balance') ?? 0);
    }

    /** Whether the E-Wallet feature is switched on. */
    public function enabled(): bool
    {
        return (bool) (StoreSetting::query()->value('wallet_enabled') ?? false);
    }

    public function allowsNegative(): bool
    {
        return (bool) (StoreSetting::query()->value('wallet_allow_negative') ?? false);
    }

    /**
     * Credit (add funds to) a wallet. Safe to call inside an outer DB
     * transaction — it participates via a savepoint.
     */
    public function credit(Wallet $wallet, float $amount, string $source, array $opts = []): WalletTransaction
    {
        return $this->apply($wallet, 'credit', $amount, $source, $opts);
    }

    /**
     * Debit (deduct funds from) a wallet. Throws WalletException when the balance
     * is insufficient (unless negative balances are allowed) or the wallet is frozen.
     */
    public function debit(Wallet $wallet, float $amount, string $source, array $opts = []): WalletTransaction
    {
        return $this->apply($wallet, 'debit', $amount, $source, $opts);
    }

    /**
     * Core ledger mutation under a row lock.
     */
    protected function apply(Wallet $wallet, string $type, float $amount, string $source, array $opts): WalletTransaction
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new WalletException(__('messages.WalletInvalidAmount'));
        }

        return DB::transaction(function () use ($wallet, $type, $amount, $source, $opts) {
            // Re-read the row under a lock so concurrent debits can't oversell the balance.
            $locked = Wallet::whereKey($wallet->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'active') {
                throw new WalletException(__('messages.WalletFrozen'));
            }

            $current = (float) $locked->balance;

            if ($type === 'debit') {
                $newBalance = round($current - $amount, 2);
                if ($newBalance < 0 && ! $this->allowsNegative()) {
                    throw new WalletException(__('messages.WalletInsufficientBalance'), 422, [
                        'balance' => $current,
                        'required' => $amount,
                    ]);
                }
            } else {
                $newBalance = round($current + $amount, 2);
            }

            $locked->balance = $newBalance;
            $locked->save();

            $tx = WalletTransaction::create([
                'wallet_id' => $locked->id,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'source' => $source,
                'reference_type' => $opts['reference_type'] ?? null,
                'reference_id' => $opts['reference_id'] ?? null,
                'note' => $opts['note'] ?? null,
                'created_by' => $opts['created_by'] ?? null,
            ]);

            // Keep the caller's in-memory model in sync with the locked row.
            $wallet->balance = $newBalance;

            return $tx;
        });
    }

    /**
     * Redeem a gift-card / wallet-item code into a client's wallet balance.
     * Returns [wallet, transaction, giftCard]. Throws WalletException on any
     * invalid/expired/already-redeemed code.
     */
    public function redeemGiftCard(string $code, int $clientId, ?int $userId = null): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            throw new WalletException(__('messages.GiftCardCodeRequired'));
        }

        return DB::transaction(function () use ($code, $clientId, $userId) {
            $card = GiftCard::where('code', $code)->lockForUpdate()->first();

            if (! $card) {
                throw new WalletException(__('messages.GiftCardInvalid'), 404);
            }

            // Lazily flip expired cards so status stays truthful.
            if ($card->status === 'active' && $card->expires_at !== null && $card->expires_at->isPast()) {
                $card->status = 'expired';
                $card->save();
            }

            if (! $card->isRedeemable()) {
                throw new WalletException(__('messages.GiftCardNotRedeemable'), 422);
            }

            $value = round((float) $card->balance, 2);
            if ($value <= 0) {
                throw new WalletException(__('messages.GiftCardNotRedeemable'), 422);
            }

            $wallet = $this->walletFor($clientId);

            $tx = $this->credit($wallet, $value, 'gift_card', [
                'reference_type' => GiftCard::class,
                'reference_id' => $card->id,
                'note' => __('messages.GiftCardRedeemNote', ['code' => $card->code]),
                'created_by' => $userId,
            ]);

            $card->balance = 0;
            $card->status = 'redeemed';
            $card->redeemed_by = $clientId;
            $card->redeemed_at = Carbon::now();
            $card->save();

            return ['wallet' => $wallet->fresh(), 'transaction' => $tx, 'gift_card' => $card];
        });
    }

    /**
     * Generate a unique, human-friendly gift-card code (e.g. GC-3F9K-8QLZ).
     */
    public function generateGiftCardCode(string $prefix = 'GC'): string
    {
        do {
            $code = strtoupper($prefix.'-'.Str::random(4).'-'.Str::random(4));
        } while (GiftCard::withTrashed()->where('code', $code)->exists());

        return $code;
    }
}
