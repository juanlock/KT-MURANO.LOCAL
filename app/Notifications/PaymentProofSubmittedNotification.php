<?php

namespace App\Notifications;

use App\Models\OnlineOrder;
use App\Models\OnlineOrderPaymentProof;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A shopper uploaded proof of an offline payment (GCash / bank transfer);
 * an admin has to verify it before the order counts as paid.
 */
class PaymentProofSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected OnlineOrder $order,
        protected OnlineOrderPaymentProof $proof
    ) {
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'payment_proof_submitted',
            'order_id' => $this->order->id,
            'ref' => $this->order->ref,
            'proof_id' => $this->proof->id,
            'amount' => (float) $this->proof->amount,
            'reference_number' => $this->proof->reference_number,
            'payment_method' => $this->proof->payment_method,
            'message' => __('messages.PaymentProofSubmitted').': '.$this->order->ref,
        ];
    }
}
