<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\StoreSetting;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WalletWithdrawal;
use App\Services\WalletException;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomerWalletController extends Controller
{
    public function __construct(private WalletService $wallets)
    {
    }

    /**
     * Wallet balance + recent activity + withdrawal config for the logged-in
     * storefront customer. Returns 403 if the E-Wallet feature is off.
     */
    public function summary(Request $request)
    {
        [$user, $error] = $this->requireCustomer();
        if ($error) {
            return $error;
        }

        $wallet = $this->wallets->walletFor((int) $user->client_id);

        $transactions = $wallet->transactions()
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (WalletTransaction $t) => $this->presentTransaction($t));

        $pending = WalletWithdrawal::where('wallet_id', $wallet->id)
            ->whereIn('status', ['pending', 'approved'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (WalletWithdrawal $w) => [
                'id' => $w->id,
                'amount' => (float) $w->amount,
                'status' => $w->status,
                'created_at' => optional($w->created_at)->toDateTimeString(),
            ]);

        return response()->json([
            'balance' => (float) $wallet->balance,
            'currency' => (string) (StoreSetting::query()->value('currency_code') ?: ''),
            'status' => $wallet->status,
            'withdrawal_enabled' => (bool) (StoreSetting::query()->value('wallet_withdrawal_enabled') ?? false),
            'min_withdrawal' => (float) (StoreSetting::query()->value('wallet_min_withdrawal') ?? 0),
            'transactions' => $transactions,
            'pending_withdrawals' => $pending,
        ]);
    }

    public function transactions(Request $request)
    {
        [$user, $error] = $this->requireCustomer();
        if ($error) {
            return $error;
        }

        $wallet = $this->wallets->walletFor((int) $user->client_id);
        $per = (int) $request->query('per_page', 15);

        $rows = $wallet->transactions()->orderByDesc('id')->paginate($per);

        return response()->json([
            'data' => $rows->getCollection()->map(fn (WalletTransaction $t) => $this->presentTransaction($t)),
            'meta' => [
                'total' => $rows->total(),
                'page' => $rows->currentPage(),
                'pages' => $rows->lastPage(),
            ],
        ]);
    }

    /**
     * Redeem a gift-card / wallet-item code into the customer's balance.
     */
    public function redeem(Request $request)
    {
        [$user, $error] = $this->requireCustomer();
        if ($error) {
            return $error;
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:60']]);

        try {
            $result = $this->wallets->redeemGiftCard($data['code'], (int) $user->client_id);
        } catch (WalletException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'success' => true,
            'credited' => (float) $result['transaction']->amount,
            'balance' => (float) $result['wallet']->balance,
        ]);
    }

    /**
     * Request a withdrawal (cash-out). Held as pending until an admin approves.
     */
    public function withdraw(Request $request)
    {
        [$user, $error] = $this->requireCustomer();
        if ($error) {
            return $error;
        }

        if (! (bool) (StoreSetting::query()->value('wallet_withdrawal_enabled') ?? false)) {
            return response()->json(['error' => __('messages.WithdrawalsDisabled')], 422);
        }

        $min = (float) (StoreSetting::query()->value('wallet_min_withdrawal') ?? 0);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['nullable', 'string', 'max:60'],
            'destination' => ['nullable', 'string', 'max:190'],
        ]);

        $amount = round((float) $data['amount'], 2);
        $wallet = $this->wallets->walletFor((int) $user->client_id);

        if ($amount < $min) {
            return response()->json(['error' => __('messages.WithdrawalBelowMinimum', ['min' => $min])], 422);
        }
        if ($amount > (float) $wallet->balance) {
            return response()->json(['error' => __('messages.WalletInsufficientBalance')], 422);
        }

        // Prevent stacking requests that together exceed the available balance.
        $alreadyRequested = (float) WalletWithdrawal::where('wallet_id', $wallet->id)
            ->where('status', 'pending')
            ->sum('amount');
        if (($alreadyRequested + $amount) > (float) $wallet->balance) {
            return response()->json(['error' => __('messages.WithdrawalExceedsAvailable')], 422);
        }

        $withdrawal = WalletWithdrawal::create([
            'wallet_id' => $wallet->id,
            'amount' => $amount,
            'status' => 'pending',
            'method' => $data['method'] ?? null,
            'destination' => $data['destination'] ?? null,
        ]);

        return response()->json(['success' => true, 'id' => $withdrawal->id], 201);
    }

    /**
     * Ensure a logged-in storefront customer with a linked client and an active
     * wallet feature. Returns [user, null] or [null, jsonResponse].
     */
    private function requireCustomer(): array
    {
        $user = Auth::guard('store')->user();
        if (! $user) {
            return [null, response()->json(['error' => 'Unauthenticated'], 401)];
        }
        if (! $user->client_id) {
            return [null, response()->json(['error' => __('messages.CustomerProfileMissing')], 422)];
        }
        if (! (bool) (StoreSetting::query()->value('wallet_enabled') ?? false)) {
            return [null, response()->json(['error' => __('messages.WalletDisabled')], 403)];
        }

        return [$user, null];
    }

    private function presentTransaction(WalletTransaction $t): array
    {
        return [
            'id' => $t->id,
            'type' => $t->type,
            'amount' => (float) $t->amount,
            'balance_after' => (float) $t->balance_after,
            'source' => $t->source,
            'note' => $t->note,
            'created_at' => optional($t->created_at)->toDateTimeString(),
        ];
    }
}
