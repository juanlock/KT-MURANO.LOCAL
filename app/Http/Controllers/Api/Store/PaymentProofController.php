<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\OnlineOrder;
use App\Models\OnlineOrderPaymentProof;
use App\Models\User;
use App\Notifications\PaymentProofSubmittedNotification;
use App\Services\StorePaymentMethodService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Storefront side of proof-of-payment: the shopper uploads a screenshot with
 * the reference number and the amount they sent, and can re-submit after a
 * rejection. Approving is admin-only (OnlineOrdersApiController).
 */
class PaymentProofController extends Controller
{
    /** Where the screenshots live (public/images per this project's convention). */
    public const UPLOAD_DIR = 'images/payment_proofs';

    /** GET /store/my/orders/{id}/payment-proofs */
    public function index(Request $request, $id)
    {
        $order = $this->ownedOrder($id);

        return response()->json([
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status ?? 'pending',
            'can_submit' => $this->canSubmit($order),
            'instructions' => StorePaymentMethodService::get($order->payment_method),
            'proofs' => $this->serialize($order),
        ]);
    }

    /** POST /store/my/orders/{id}/payment-proofs */
    public function store(Request $request, $id)
    {
        $order = $this->ownedOrder($id);

        if (! StorePaymentMethodService::requiresProof($order->payment_method)) {
            return response()->json(['error' => __('messages.ProofNotApplicable')], 422);
        }
        if (($order->payment_status ?? 'pending') === 'paid') {
            return response()->json(['error' => __('messages.OrderAlreadyPaid')], 422);
        }
        if ($order->status === 'cancelled') {
            return response()->json(['error' => __('messages.OrderCancelled')], 422);
        }
        // One review at a time: a pending submission must be handled first.
        if ($order->paymentProofs()->where('status', 'pending')->exists()) {
            return response()->json(['error' => __('messages.ProofAlreadyPending')], 422);
        }

        $data = $request->validate([
            'reference_number' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        $dir = public_path(self::UPLOAD_DIR);
        if (! File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'jpg');
        $filename = (string) Str::uuid().'.'.$ext;
        $file->move($dir, $filename);

        $proof = $order->paymentProofs()->create([
            'payment_method' => $order->payment_method,
            'reference_number' => trim($data['reference_number']),
            'amount' => round((float) $data['amount'], 2),
            'paid_at' => $data['paid_at'] ?? now()->toDateString(),
            'file_path' => $filename,
            'note' => $data['note'] ?? null,
            'status' => 'pending',
        ]);

        $this->notifyAdmins($order, $proof);

        return response()->json([
            'success' => true,
            'proof' => $this->serializeOne($proof),
            'can_submit' => false,
        ], 201);
    }

    /** The order, scoped to the signed-in customer (404 for anyone else's). */
    protected function ownedOrder($id): OnlineOrder
    {
        $user = Auth::guard('store')->user();
        abort_unless($user, 401);
        abort_unless($user->client_id, 403);

        return OnlineOrder::where('id', $id)
            ->where('client_id', (int) $user->client_id)
            ->firstOrFail();
    }

    protected function canSubmit(OnlineOrder $order): bool
    {
        return StorePaymentMethodService::requiresProof($order->payment_method)
            && ($order->payment_status ?? 'pending') !== 'paid'
            && $order->status !== 'cancelled'
            && ! $order->paymentProofs()->where('status', 'pending')->exists();
    }

    protected function serialize(OnlineOrder $order): array
    {
        return $order->paymentProofs()->orderByDesc('id')->get()
            ->map(fn ($p) => $this->serializeOne($p))->all();
    }

    protected function serializeOne(OnlineOrderPaymentProof $p): array
    {
        return [
            'id' => $p->id,
            'reference_number' => $p->reference_number,
            'amount' => (float) $p->amount,
            'paid_at' => optional($p->paid_at)->toDateString(),
            'note' => $p->note,
            'status' => $p->status,
            'reject_reason' => $p->reject_reason,
            'file_url' => $p->fileUrl(),
            'submitted_at' => optional($p->created_at)->toDateTimeString(),
        ];
    }

    /** Tell the staff who can act on online orders that money is waiting. */
    protected function notifyAdmins(OnlineOrder $order, OnlineOrderPaymentProof $proof): void
    {
        try {
            // Same audience as the new-order bell notification.
            $admins = User::where('role_id', 1)->whereNull('deleted_at')->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new PaymentProofSubmittedNotification($order, $proof));
            }
        } catch (\Throwable $e) {
            // A notification failure must never lose the shopper's upload.
            \Illuminate\Support\Facades\Log::warning('Payment proof notification failed: '.$e->getMessage());
        }
    }
}
