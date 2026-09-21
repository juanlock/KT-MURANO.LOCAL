<?php

namespace App\Mail;

use App\Models\OnlineOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AdminNewOrderMail extends Mailable
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
        return $this->subject('New Online Order — '.$this->order->ref)
            ->markdown('emails.admin_new_order')
            ->with(['order' => $this->order, 'storeName' => $this->storeName]);
    }
}
