<?php

namespace App\Notifications;

use App\Models\MrpProductionOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells planners that a production order cannot start because material is
 * missing, and names exactly what is short.
 *
 * Database channel only. Mail is deliberately not used: a shortage is an
 * operational condition that may resolve within the hour as a delivery lands,
 * and emailing every one of them is how people learn to ignore the alerts that
 * matter.
 */
class MrpShortageNotification extends Notification
{
    use Queueable;

    protected MrpProductionOrder $order;

    /** @var array<int, array{product_name:string, short_by:float}> */
    protected array $shortages;

    public function __construct(MrpProductionOrder $order, array $shortages)
    {
        $this->order = $order;
        $this->shortages = $shortages;
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toArray($notifiable)
    {
        $names = collect($this->shortages)->take(3)->pluck('product_name')->filter()->all();
        $extra = max(0, count($this->shortages) - count($names));

        $summary = implode(', ', $names).($extra > 0 ? " and {$extra} more" : '');

        return [
            'type' => 'mrp_shortage',
            'title' => 'Production order '.$this->order->reference.' is short of material',
            'message' => $summary !== ''
                ? 'Waiting on: '.$summary
                : 'One or more components are not in stock.',
            'production_order_id' => $this->order->id,
            'reference' => $this->order->reference,
            'shortage_count' => count($this->shortages),
            'shortages' => collect($this->shortages)->take(10)->map(fn ($s) => [
                'product_name' => $s['product_name'] ?? null,
                'required' => $s['required'] ?? null,
                'available' => $s['available'] ?? null,
                'short_by' => $s['short_by'] ?? null,
            ])->values()->all(),
            'url' => '/mrp/production-orders/'.$this->order->id,
        ];
    }
}
