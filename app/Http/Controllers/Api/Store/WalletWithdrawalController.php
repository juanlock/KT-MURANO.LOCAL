<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\StoreSetting;
use App\Models\WalletWithdrawal;
use App\Services\WalletException;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class WalletWithdrawalController extends Controller
{
    public function __construct(private WalletService $wallets)
    {
    }


    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', WalletWithdrawal::class);

        $per = (int) $request->query('per_page', 10);
        $status = $request->query('status');

        $query = WalletWithdrawal::with('wallet.client:id,name,email')->orderByDesc('id');
        if ($status && in_array($status, WalletWithdrawal::STATUSES, true)) {
            $query->where('status', $status);
        }

        $rows = $query->paginate($per);

        return response()->json([
            'data' => $rows->getCollection()->map(fn (WalletWithdrawal $w) => [
                'id' => $w->id,
                'wallet_id' => $w->wallet_id,
                'client_name' => optional(optional($w->wallet)->client)->name,
                'client_email' => optional(optional($w->wallet)->client)->email,
                'amount' => (float) $w->amount,
                'status' => $w->status,
                'method' => $w->method,
                'destination' => $w->destination,
                'note' => $w->note,
                'created_at' => optional($w->created_at)->toDateTimeString(),
                'processed_at' => optional($w->processed_at)->toDateTimeString(),
            ]),
            'meta' => [
                'total' => $rows->total(),
                'page' => $rows->currentPage(),
                'pages' => $rows->lastPage(),
            ],
        ]);
    }

    /**
     * Approve a pending request: debit the wallet and mark it approved.
     * Runs under a row lock so a concurrent/duplicate approval cannot
     * debit the wallet twice.
     */
    public function approve(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', WalletWithdrawal::class);

        $note = $request->input('note');

        try {
            \DB::transaction(function () use ($id, $note) {
                $withdrawal = WalletWithdrawal::with('wallet')->lockForUpdate()->findOrFail($id);
                if ($withdrawal->status !== 'pending') {
                    throw new WalletException(__('messages.WithdrawalNotPending'));
                }

                $this->wallets->debit($withdrawal->wallet, (float) $withdrawal->amount, 'withdrawal', [
                    'reference_type' => WalletWithdrawal::class,
                    'reference_id' => $withdrawal->id,
                    'note' => __('messages.WithdrawalApprovedNote'),
                    'created_by' => Auth::id(),
                ]);

                $withdrawal->update([
                    'status' => 'approved',
                    'note' => $note,
                    'processed_by' => Auth::id(),
                    'processed_at' => Carbon::now(),
                ]);
            });
        } catch (WalletException $e) {
            return $e->toResponse();
        }

        return response()->json(['success' => true]);
    }

    /**
     * Mark an approved withdrawal as paid out.
     */
    public function markPaid(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', WalletWithdrawal::class);

        $withdrawal = WalletWithdrawal::findOrFail($id);
        if (! in_array($withdrawal->status, ['approved'], true)) {
            return response()->json(['error' => __('messages.WithdrawalNotApproved')], 422);
        }

        $withdrawal->update([
            'status' => 'paid',
            'processed_by' => Auth::id(),
            'processed_at' => Carbon::now(),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Reject a pending request (no balance change).
     */
    public function reject(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', WalletWithdrawal::class);

        $withdrawal = WalletWithdrawal::findOrFail($id);
        if ($withdrawal->status !== 'pending') {
            return response()->json(['error' => __('messages.WithdrawalNotPending')], 422);
        }

        $withdrawal->update([
            'status' => 'rejected',
            'note' => $request->input('note'),
            'processed_by' => Auth::id(),
            'processed_at' => Carbon::now(),
        ]);

        return response()->json(['success' => true]);
    }
}
