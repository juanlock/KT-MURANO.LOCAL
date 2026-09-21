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

class WalletController extends Controller
{
    public function __construct(private WalletService $wallets)
    {
    }


    /**
     * Admin E-Wallet dashboard: headline stats + top wallets + recent activity
     * + pending withdrawal requests, all in one payload.
     */
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Wallet::class);

        $totalWallets = Wallet::count();
        $activeWallets = Wallet::where('status', 'active')->where('balance', '>', 0)->count();
        $inCirculation = (float) Wallet::sum('balance');

        $totalCredits = (float) WalletTransaction::where('type', 'credit')->sum('amount');
        $totalDebits = (float) WalletTransaction::where('type', 'debit')->sum('amount');

        $pendingWithdrawals = WalletWithdrawal::where('status', 'pending')->count();
        $pendingAmount = (float) WalletWithdrawal::where('status', 'pending')->sum('amount');
        $totalWithdrawn = (float) WalletWithdrawal::whereIn('status', ['approved', 'paid'])->sum('amount');

        $topWallets = Wallet::with('client:id,name')
            ->where('balance', '>', 0)
            ->orderByDesc('balance')
            ->limit(5)
            ->get()
            ->map(fn (Wallet $w) => [
                'id' => $w->id,
                'client_id' => $w->client_id,
                'client_name' => optional($w->client)->name ?: ('#'.$w->client_id),
                'balance' => (float) $w->balance,
                'status' => $w->status,
            ]);

        $recentTransactions = WalletTransaction::with('wallet.client:id,name')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (WalletTransaction $t) => $this->presentTransaction($t));

        $pendingRequests = WalletWithdrawal::with('wallet.client:id,name')
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->limit(10)
            ->get()
            ->map(fn (WalletWithdrawal $w) => $this->presentWithdrawal($w));

        return response()->json([
            'currency' => (string) (StoreSetting::query()->value('currency_code') ?: ''),
            // POS spends wallets through the payment method named "Wallet";
            // the admin pages warn when it is missing (e.g. renamed after an
            // id-8 collision).
            'wallet_payment_method_exists' => $this->wallets->paymentMethodId() !== null,
            'stats' => [
                'total_wallets' => $totalWallets,
                'active_wallets' => $activeWallets,
                'in_circulation' => $inCirculation,
                'total_credits' => $totalCredits,
                'total_debits' => $totalDebits,
                'pending_withdrawals' => $pendingWithdrawals,
                'pending_amount' => $pendingAmount,
                'total_withdrawn' => $totalWithdrawn,
            ],
            'top_wallets' => $topWallets,
            'recent_transactions' => $recentTransactions,
            'pending_requests' => $pendingRequests,
        ]);
    }

    /**
     * Paginated wallet list (search by customer name).
     */
    public function wallets(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Wallet::class);

        $per = (int) $request->query('per_page', 10);

        $query = Wallet::with('client:id,name,email')->orderByDesc('balance');

        if ($request->filled('search')) {
            $term = $request->query('search');
            $query->whereHas('client', fn ($q) => $q->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%"));
        }

        $wallets = $query->paginate($per);

        return response()->json([
            'data' => $wallets->getCollection()->map(fn (Wallet $w) => [
                'id' => $w->id,
                'client_id' => $w->client_id,
                'client_name' => optional($w->client)->name ?: ('#'.$w->client_id),
                'client_email' => optional($w->client)->email,
                'balance' => (float) $w->balance,
                'currency' => $w->currency,
                'status' => $w->status,
                'created_at' => optional($w->created_at)->toDateTimeString(),
            ]),
            'meta' => [
                'total' => $wallets->total(),
                'page' => $wallets->currentPage(),
                'pages' => $wallets->lastPage(),
            ],
        ]);
    }

    /**
     * A single wallet with its full transaction history.
     */
    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Wallet::class);

        $wallet = Wallet::with('client:id,name,email')->findOrFail($id);

        $transactions = $wallet->transactions()
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (WalletTransaction $t) => $this->presentTransaction($t));

        return response()->json([
            'wallet' => [
                'id' => $wallet->id,
                'client_id' => $wallet->client_id,
                'client_name' => optional($wallet->client)->name ?: ('#'.$wallet->client_id),
                'client_email' => optional($wallet->client)->email,
                'balance' => (float) $wallet->balance,
                'currency' => $wallet->currency,
                'status' => $wallet->status,
            ],
            'transactions' => $transactions,
        ]);
    }

    /**
     * Manual admin adjustment: credit or debit a wallet with a note.
     */
    public function adjust(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Wallet::class);

        $data = $request->validate([
            'type' => ['required', 'in:credit,debit'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $wallet = Wallet::findOrFail($id);

        try {
            $tx = $data['type'] === 'credit'
                ? $this->wallets->credit($wallet, (float) $data['amount'], 'adjustment', [
                    'note' => $data['note'] ?? null,
                    'created_by' => Auth::id(),
                ])
                : $this->wallets->debit($wallet, (float) $data['amount'], 'adjustment', [
                    'note' => $data['note'] ?? null,
                    'created_by' => Auth::id(),
                ]);
        } catch (WalletException $e) {
            return $e->toResponse();
        }

        return response()->json([
            'success' => true,
            'balance' => (float) $wallet->fresh()->balance,
            'transaction' => $this->presentTransaction($tx->fresh('wallet')),
        ]);
    }

    /**
     * Freeze / unfreeze a wallet.
     */
    public function setStatus(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Wallet::class);

        $data = $request->validate(['status' => ['required', 'in:active,frozen']]);
        $wallet = Wallet::findOrFail($id);
        $wallet->status = $data['status'];
        $wallet->save();

        return response()->json(['success' => true, 'status' => $wallet->status]);
    }

    /**
     * E-Wallet settings only (dedicated module settings page).
     */
    public function settings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Wallet::class);

        $s = StoreSetting::first();

        return response()->json([
            'wallet_enabled' => (bool) ($s->wallet_enabled ?? false),
            'wallet_allow_negative' => (bool) ($s->wallet_allow_negative ?? false),
            'wallet_refund_destination' => (string) ($s->wallet_refund_destination ?? 'wallet'),
            'wallet_withdrawal_enabled' => (bool) ($s->wallet_withdrawal_enabled ?? false),
            'wallet_min_withdrawal' => (float) ($s->wallet_min_withdrawal ?? 10),
            'wallet_payment_method_exists' => $this->wallets->paymentMethodId() !== null,
        ]);
    }

    /**
     * Save E-Wallet settings (only the wallet_* columns).
     */
    public function updateSettings(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Wallet::class);

        foreach (['wallet_enabled', 'wallet_allow_negative', 'wallet_withdrawal_enabled'] as $boolField) {
            if ($request->has($boolField)) {
                $request->merge([
                    $boolField => (int) filter_var($request->input($boolField), FILTER_VALIDATE_BOOLEAN),
                ]);
            }
        }

        $data = $request->validate([
            'wallet_enabled' => 'nullable|in:0,1',
            'wallet_allow_negative' => 'nullable|in:0,1',
            'wallet_refund_destination' => 'nullable|in:wallet,original',
            'wallet_withdrawal_enabled' => 'nullable|in:0,1',
            'wallet_min_withdrawal' => 'nullable|numeric|min:0',
        ]);

        $s = StoreSetting::first() ?: new StoreSetting;
        $s->fill($data)->save();

        return response()->json(['success' => true]);
    }

    private function presentTransaction(WalletTransaction $t): array
    {
        return [
            'id' => $t->id,
            'wallet_id' => $t->wallet_id,
            'client_name' => optional(optional($t->wallet)->client)->name,
            'type' => $t->type,
            'amount' => (float) $t->amount,
            'balance_after' => (float) $t->balance_after,
            'source' => $t->source,
            'note' => $t->note,
            'created_at' => optional($t->created_at)->toDateTimeString(),
        ];
    }

    private function presentWithdrawal(WalletWithdrawal $w): array
    {
        return [
            'id' => $w->id,
            'wallet_id' => $w->wallet_id,
            'client_name' => optional(optional($w->wallet)->client)->name,
            'amount' => (float) $w->amount,
            'status' => $w->status,
            'method' => $w->method,
            'destination' => $w->destination,
            'note' => $w->note,
            'created_at' => optional($w->created_at)->toDateTimeString(),
        ];
    }
}
