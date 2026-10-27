<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CartUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @var array */
    public $cart;

    /** @var bool */
    public $completed;

    /** @var string Screen ID for multi-display support (e.g. '1', '2') */
    public $screenId;

    /**
     * Create a new event instance.
     */
    public function __construct(array $cart, bool $completed = false, string $screenId = '1')
    {
        $this->cart = $cart;
        $this->completed = $completed;
        $this->screenId = $screenId;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new Channel('pos-cart.'.$this->screenId);
    }

    public function broadcastAs()
    {
        return 'CartUpdated';
    }

    /**
     * Only with a real broadcaster (Pusher / Reverb...): with "log" or "null" the queued
     * broadcast went nowhere and only filled the queue (2026-10-27).
     */
    public function broadcastWhen(): bool
    {
        return ! in_array(config('broadcasting.default'), ['log', 'null', null], true);
    }
}
