<?php

namespace App\Notifications;

use App\Models\OnlineOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Records a new online order for store admins (database channel → admin bell).
 * Email to the store owner is sent separately via AdminNewOrderMail so we don't
 * email every admin user individually.
 */
class NewOnlineOrderNotification extends Notification
{
    use Queueable;

    protected OnlineOrder $order;

    public function __construct(OnlineOrder $order)
    {
        $this->order = $order;
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'new_online_order',
            'order_id' => $this->order->id,
            'ref' => $this->order->ref,
            'total' => (float) $this->order->total,
            'customer_name' => $this->order->customer_name,
            'is_flagged' => (bool) $this->order->is_flagged,
            'message' => __('messages.NewOnlineOrderReceived').': '.$this->order->ref,
        ];
    }
}
