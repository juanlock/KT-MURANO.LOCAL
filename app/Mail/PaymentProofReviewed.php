<?php

namespace App\Mail;

use App\Models\OnlineOrder;
use App\Models\OnlineOrderPaymentProof;
use App\Models\StoreSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the shopper whether the payment proof they uploaded was accepted.
 */
class PaymentProofReviewed extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public OnlineOrder $order,
        public OnlineOrderPaymentProof $proof
    ) {
    }

    public function build()
    {
        $storeName = StoreSetting::query()->value('store_name') ?: config('app.name');
        $approved = $this->proof->status === 'approved';

        $subject = $approved
            ? __('messages.PaymentConfirmedSubject', ['ref' => $this->order->ref])
            : __('messages.PaymentProofRejectedSubject', ['ref' => $this->order->ref]);

        return $this->subject($subject)
            ->markdown('emails.payment_proof_reviewed')
            ->with([
                'order' => $this->order,
                'proof' => $this->proof,
                'approved' => $approved,
                'storeName' => $storeName,
                'orderUrl' => url('/'.store_path_to('account/orders/'.$this->order->id)),
            ]);
    }
}
