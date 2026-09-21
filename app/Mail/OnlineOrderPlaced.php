<?php

namespace App\Mail;

use App\Models\OnlineOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OnlineOrderPlaced extends Mailable
{
    use Queueable, SerializesModels;

    public OnlineOrder $order;

    public $storeName;

    public function __construct(OnlineOrder $order, $storeName = null)
    {
        $this->order = $order;
        $this->storeName = $storeName;
    }

    public function build()
    {
        return $this->subject('Order Confirmation — '.$this->order->ref)
            ->markdown('emails.online_order_placed')
            ->with(['order' => $this->order, 'storeName' => $this->storeName]);
    }
}
